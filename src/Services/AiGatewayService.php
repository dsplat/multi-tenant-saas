<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use MultiTenantSaas\Contracts\AiProviderContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Enums\AiModelEnum;
use MultiTenantSaas\Modules\Ai\Models\AiModelAlias;
use MultiTenantSaas\Modules\Ai\Models\AiRequest;
use MultiTenantSaas\Modules\Ai\Services\Ai\Providers\LaravelAiProviderAdapter;
use MultiTenantSaas\Modules\Ai\Services\Ai\ZhipuProvider;
use Throwable;

/**
 * AI 网关服务
 *
 * 核心服务层，统一对外暴露 AI 能力（对话、补全、向量、流式对话），对上层屏蔽
 * 提供商差异与底层细节。职责：
 *  - 模型路由：将传入模型（别名 / 枚举值 / 原始模型名）解析为实际模型与提供商标识
 *  - 提供商注册：按 provider 标识映射到 AiProviderContract 实现，复用实例
 *  - 速率限制：按租户 + 用户维度执行网关级 RPM 限流（受 ai.rate_limit 配置控制）
 *  - 重试策略：按 ai.retry 配置对瞬时失败进行指数退避重试
 *  - 请求日志：每次调用落库 AiRequest，记录 token 用量、耗时、费用、状态与错误
 *  - 流式开关：受 ai.streaming_enabled 全局开关控制
 *
 * 依赖：AiProviderContract（提供商实现）、AiModelEnum（模型枚举）、
 * AiModelAlias（别名映射）、AiRequest（请求日志）、TenantContextContract（租户上下文）。
 */
class AiGatewayService
{
    /**
     * 提供商标识与实现类的映射表
     *
     * 仅注册已实现的提供商；未注册的 provider 会在运行时抛出
     * ai.provider_not_implemented 异常，便于后续按需扩展。
     */
    protected const PROVIDER_CLASS_MAP = [
        // laravel/ai 原生 provider（支持 OpenAI、Anthropic、Gemini、DeepSeek、Groq）
        'openai' => LaravelAiProviderAdapter::class,
        'anthropic' => LaravelAiProviderAdapter::class,
        'gemini' => LaravelAiProviderAdapter::class,
        'deepseek' => LaravelAiProviderAdapter::class,
        'groq' => LaravelAiProviderAdapter::class,
        // OpenAI 兼容模式 provider（智谱等自定义 base_url）
        'zhipu' => ZhipuProvider::class,
    ];

    /**
     * prompt_summary 截断长度
     */
    protected const PROMPT_SUMMARY_LIMIT = 200;

    /**
     * 已实例化的提供商缓存（按 provider 标识缓存）
     *
     * @var array<string, AiProviderContract>
     */
    protected array $providerCache = [];

    public function __construct(
        protected TenantContextContract $tenantContext,
    ) {}

    /**
     * 对话补全
     *
     * @param  string  $model  模型标识（别名 / 枚举值 / 原始模型名）
     * @param  array<int, array{role: string, content: string|null}>  $messages  对话消息列表
     * @param  array<string, mixed>  $options  附加请求参数（temperature、max_tokens、tools 等）
     * @return array{
     *     id: string|null, object: string|null, model: string, role: string,
     *     content: string, tool_calls: array|null, finish_reason: string|null,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 参数非法、模型不可用、限流或重试耗尽时抛出
     */
    public function chat(string $model, array $messages, array $options = []): array
    {
        $this->assertMessages($messages);

        [$actualModel, $providerCode] = $this->resolveModel($model);
        $provider = $this->resolveProvider($providerCode);

        $this->enforceRateLimit();

        $log = $this->createLog(
            model: $actualModel,
            provider: $providerCode,
            promptSummary: $this->summarizeMessages($messages),
            options: $options,
        );

        $start = microtime(true);

        try {
            $response = $this->retry(
                fn () => $provider->chatCompletion($actualModel, $messages, $options),
                $actualModel,
            );

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: $response['usage'] ?? [],
                errorMessage: null,
            );

            return $response;
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * 文本补全
     *
     * @param  string  $model  模型标识
     * @param  string  $prompt  补全提示文本
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array{
     *     id: string|null, object: string|null, model: string, text: string,
     *     finish_reason: string|null, usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 参数非法、模型不可用、限流或重试耗尽时抛出
     */
    public function complete(string $model, string $prompt, array $options = []): array
    {
        $this->assertPrompt($prompt);

        [$actualModel, $providerCode] = $this->resolveModel($model);
        $provider = $this->resolveProvider($providerCode);

        $this->enforceRateLimit();

        $log = $this->createLog(
            model: $actualModel,
            provider: $providerCode,
            promptSummary: $this->summarizePrompt($prompt),
            options: $options,
        );

        $start = microtime(true);

        try {
            $response = $this->retry(
                fn () => $provider->textCompletion($actualModel, $prompt, $options),
                $actualModel,
            );

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: $response['usage'] ?? [],
                errorMessage: null,
            );

            return $response;
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * 向量嵌入
     *
     * @param  string  $model  模型标识
     * @param  string|array<int, string>  $input  单条或多条文本输入
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array{
     *     model: string, object: string|null,
     *     data: array<int, array{index: int|null, embedding: array<int, float>, object: string|null}>,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 参数非法、模型不可用、限流或重试耗尽时抛出
     */
    public function embed(string $model, string|array $input, array $options = []): array
    {
        $this->assertInput($input);

        [$actualModel, $providerCode] = $this->resolveModel($model);
        $provider = $this->resolveProvider($providerCode);

        $this->enforceRateLimit();

        $log = $this->createLog(
            model: $actualModel,
            provider: $providerCode,
            promptSummary: $this->summarizeInput($input),
            options: $options,
        );

        $start = microtime(true);

        try {
            $response = $this->retry(
                fn () => $provider->embeddings($actualModel, $input, $options),
                $actualModel,
            );

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: $response['usage'] ?? [],
                errorMessage: null,
            );

            return $response;
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * 流式对话补全
     *
     * 受 ai.streaming_enabled 全局开关控制；关闭时直接抛出异常。
     * 逐块产出标准化片段，结构同 AiProviderContract::streamChatCompletion。
     *
     * @param  string  $model  模型标识
     * @param  array<int, array{role: string, content: string|null}>  $messages  对话消息列表
     * @param  array<string, mixed>  $options  附加请求参数
     * @return \Generator<int, array<string, mixed>, void, void> 流式片段生成器
     *
     * @throws \RuntimeException 参数非法、流式未启用、模型不可用、限流或上游错误时抛出
     */
    public function streamChat(string $model, array $messages, array $options = []): \Generator
    {
        if (! $this->isStreamingEnabled()) {
            throw new \RuntimeException(trans('ai.streaming_disabled'));
        }

        $this->assertMessages($messages);

        [$actualModel, $providerCode] = $this->resolveModel($model);
        $provider = $this->resolveProvider($providerCode);

        $this->enforceRateLimit();

        $log = $this->createLog(
            model: $actualModel,
            provider: $providerCode,
            promptSummary: $this->summarizeMessages($messages),
            options: $options,
        );

        $start = microtime(true);

        try {
            foreach ($provider->streamChatCompletion($actualModel, $messages, $options) as $chunk) {
                yield $chunk;
            }

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: null,
            );
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * 解析模型标识为 [实际模型名, 提供商标识]
     *
     * 解析顺序：
     *  1. AiModelAlias 表中存在激活别名 -> 使用其 actual_model 与 provider
     *  2. AiModelEnum 命中 -> 使用枚举值与 provider()
     *  3. 都未命中 -> 视为原始模型名，使用默认提供商
     *
     * @return array{0: string, 1: string} [actualModel, providerCode]
     *
     * @throws \RuntimeException 别名存在但被废弃时抛出
     */
    protected function resolveModel(string $model): array
    {
        $alias = AiModelAlias::query()
            ->active()
            ->byAlias($model)
            ->first();

        if ($alias !== null) {
            if ($alias->isDeprecated()) {
                throw new \RuntimeException(trans('ai.model_deprecated', ['model' => $model]));
            }

            $providerCode = $alias->provider ?: $this->defaultProvider();

            return [$alias->actual_model, $providerCode];
        }

        $enum = AiModelEnum::tryFrom($model);

        if ($enum !== null) {
            if ($enum->isDeprecated()) {
                throw new \RuntimeException(trans('ai.model_deprecated', ['model' => $model]));
            }

            return [$enum->value, $enum->provider()];
        }

        return [$model, $this->defaultProvider()];
    }

    /**
     * 解析提供商标识为 AiProviderContract 实例
     *
     * 实例按 provider 标识缓存复用；未注册实现的 provider 抛出异常。
     *
     * @throws \RuntimeException 提供商未实现时抛出
     */
    protected function resolveProvider(string $providerCode): AiProviderContract
    {
        if (isset($this->providerCache[$providerCode])) {
            return $this->providerCache[$providerCode];
        }

        $class = self::PROVIDER_CLASS_MAP[$providerCode] ?? null;

        if ($class === null) {
            throw new \RuntimeException(trans('ai.provider_not_implemented', ['provider' => $providerCode]));
        }

        // laravel/ai 适配器需要传入 provider 配置
        if ($class === LaravelAiProviderAdapter::class) {
            // 检查容器是否已绑定（测试注入 mock）
            if (app()->bound($class)) {
                return $this->providerCache[$providerCode] = app($class);
            }

            $config = config("ai.providers.{$providerCode}", []);
            $config['driver'] = $providerCode;

            return $this->providerCache[$providerCode] = new $class($config);
        }

        return $this->providerCache[$providerCode] = app($class);
    }

    /**
     * 执行网关级速率限制
     *
     * 仅在 ai.rate_limit.enabled 开启时生效；限流键按 租户 + 用户 维度生成，
     * 超出 RPM 上限时抛出异常。系统级调用（无租户）按 IP 维度兜底。
     *
     * @throws \RuntimeException 触发限流时抛出
     */
    protected function enforceRateLimit(): void
    {
        if (! $this->isRateLimitEnabled()) {
            return;
        }

        $key = $this->rateLimitKey();
        $max = $this->rateLimitMax();

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryIn = RateLimiter::availableIn($key);
            Log::warning('[AiGatewayService] rate limit exceeded', [
                'key' => $key,
                'retry_in' => $retryIn,
            ]);

            throw new \RuntimeException(trans('ai.rate_limited', ['seconds' => $retryIn]));
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * 带重试的调用包装
     *
     * 按 ai.retry.attempts 次数重试，每次失败间隔 ai.retry.delay_ms 毫秒。
     * 全部失败后抛出最后一次异常。
     *
     * @param  callable(): mixed  $fn  无参调用闭包
     * @param  string  $model  模型名（用于日志）
     *
     * @throws Throwable 重试耗尽后抛出最后一次异常
     */
    protected function retry(callable $fn, string $model): mixed
    {
        $attempts = $this->retryAttempts();
        $delayMs = $this->retryDelayMs();

        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $fn();
            } catch (Throwable $e) {
                $lastError = $e;

                Log::warning('[AiGatewayService] attempt failed', [
                    'model' => $model,
                    'attempt' => $attempt,
                    'max' => $attempts,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt < $attempts) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw $lastError;
    }

    /**
     * 创建请求日志（pending 状态）
     *
     * tenant_id 由 BelongsToTenant trait 从 TenantContext 自动填充；
     * user_id 从认证上下文获取，无登录用户时为 null。
     */
    protected function createLog(string $model, string $provider, string $promptSummary, array $options): AiRequest
    {
        if (! $this->logEnabled()) {
            return new AiRequest;
        }

        return AiRequest::create([
            'user_id' => $this->currentUserId(),
            'model' => $model,
            'provider' => $provider,
            'prompt_summary' => $promptSummary,
            'status' => AiRequest::STATUS_PENDING,
            'metadata' => ['options' => $this->sanitizeOptions($options)],
        ]);
    }

    /**
     * 终结请求日志（写入用量、耗时、费用、状态与错误）
     */
    protected function finalizeLog(AiRequest $log, float $start, array $usage, ?string $errorMessage): void
    {
        if (! $this->logEnabled() || ! $log->exists) {
            return;
        }

        $responseTimeMs = (int) round((microtime(true) - $start) * 1000);

        $log->response_time_ms = $responseTimeMs;
        $log->input_tokens = (int) ($usage['prompt_tokens'] ?? 0);
        $log->output_tokens = (int) ($usage['completion_tokens'] ?? 0);
        $log->cost = $this->calculateCost($log->model, $log->input_tokens, $log->output_tokens);

        if ($errorMessage !== null) {
            $log->markAsFailed($errorMessage);
        } else {
            $log->markAsSuccess();
        }

        $log->save();
    }

    /**
     * 费用估算
     *
     * 当前配置未引入模型定价表，统一返回 0.0；
     * 后续接入计费模块时按 (输入 token * 输入单价 + 输出 token * 输出单价) 计算。
     */
    protected function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        return 0.0;
    }

    /**
     * 从消息列表生成 prompt 摘要（取最后一条用户消息内容并截断）
     */
    protected function summarizeMessages(array $messages): string
    {
        $content = '';

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? [];
            if (($message['role'] ?? '') === 'user' && ! empty($message['content'])) {
                $content = (string) $message['content'];
                break;
            }
        }

        if ($content === '') {
            $last = $messages[count($messages) - 1] ?? [];
            $content = (string) ($last['content'] ?? '');
        }

        return Str::limit($content, self::PROMPT_SUMMARY_LIMIT);
    }

    /**
     * 从补全 prompt 生成摘要并截断
     */
    protected function summarizePrompt(string $prompt): string
    {
        return Str::limit($prompt, self::PROMPT_SUMMARY_LIMIT);
    }

    /**
     * 从向量输入生成摘要并截断
     *
     * @param  string|array<int, string>  $input
     */
    protected function summarizeInput(string|array $input): string
    {
        if (is_string($input)) {
            return Str::limit($input, self::PROMPT_SUMMARY_LIMIT);
        }

        $first = $input[0] ?? '';

        return Str::limit((string) $first, self::PROMPT_SUMMARY_LIMIT);
    }

    /**
     * 清洗 options（剔除敏感字段后再写入日志 metadata）
     */
    protected function sanitizeOptions(array $options): array
    {
        $sanitized = $options;
        unset($sanitized['api_key'], $sanitized['authorization'], $sanitized['headers']);

        return $sanitized;
    }

    /**
     * 校验消息列表非空
     *
     * @throws \RuntimeException 消息为空时抛出
     */
    protected function assertMessages(array $messages): void
    {
        if ($messages === []) {
            throw new \RuntimeException(trans('ai.invalid_messages'));
        }
    }

    /**
     * 校验 prompt 非空
     *
     * @throws \RuntimeException prompt 为空时抛出
     */
    protected function assertPrompt(string $prompt): void
    {
        if (trim($prompt) === '') {
            throw new \RuntimeException(trans('ai.invalid_prompt'));
        }
    }

    /**
     * 校验向量输入非空
     *
     * @param  string|array<int, string>  $input
     *
     * @throws \RuntimeException 输入为空时抛出
     */
    protected function assertInput(string|array $input): void
    {
        if (is_string($input)) {
            if (trim($input) === '') {
                throw new \RuntimeException(trans('ai.invalid_input'));
            }

            return;
        }

        if ($input === []) {
            throw new \RuntimeException(trans('ai.invalid_input'));
        }
    }

    /**
     * 限流键（租户 + 用户维度，系统级调用回退 IP）
     */
    protected function rateLimitKey(): string
    {
        $tenantId = $this->tenantContext->resolveId();
        $userId = $this->currentUserId();

        if ($tenantId !== null && $userId !== null) {
            return 'ai:rl:'.$tenantId.':'.$userId;
        }

        if ($tenantId !== null) {
            return 'ai:rl:t:'.$tenantId;
        }

        return 'ai:rl:ip:'.request()->ip();
    }

    /**
     * 当前登录用户 ID（无登录用户时返回 null）
     */
    protected function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id !== null ? (int) $id : null;
    }

    /**
     * 默认提供商标识
     */
    protected function defaultProvider(): string
    {
        return (string) config('ai.default_provider', 'openai');
    }

    /**
     * 流式总开关
     */
    protected function isStreamingEnabled(): bool
    {
        return (bool) config('ai.streaming_enabled', true);
    }

    /**
     * 限流是否启用
     */
    protected function isRateLimitEnabled(): bool
    {
        return (bool) config('ai.rate_limit.enabled', false);
    }

    /**
     * 限流 RPM 上限
     */
    protected function rateLimitMax(): int
    {
        return (int) config('ai.rate_limit.max_requests_per_minute', 60);
    }

    /**
     * 重试次数
     */
    protected function retryAttempts(): int
    {
        return max(1, (int) config('ai.retry.attempts', 2));
    }

    /**
     * 重试间隔（毫秒）
     */
    protected function retryDelayMs(): int
    {
        return max(0, (int) config('ai.retry.delay_ms', 500));
    }

    /**
     * 请求日志是否启用
     */
    protected function logEnabled(): bool
    {
        return (bool) config('ai.log.enable', true);
    }
}
