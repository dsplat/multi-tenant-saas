<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AiPrompt;
use MultiTenantSaas\Scopes\TenantScope;
use RuntimeException;

/**
 * 文本 AI 服务
 *
 * 面向上层提供 LLM 文本能力，统一通过 AiGatewayService 调用提供商，不直接发起 HTTP：
 *  - 聊天补全（单轮/多轮，system/user/assistant 角色）
 *  - 文本补全
 *  - 嵌入向量生成（支持批量）
 *  - JSON 模式输出
 *  - 流式输出（SSE）
 *  - 提示词管理：模板 CRUD、变量占位符替换、分类、版本管理、租户自定义覆盖
 *
 * 提示词解析策略：同名模板按「租户级优先、系统级兜底」解析；
 * 系统级模板（tenant_id 为 null）由迁移预置，对所有租户可见。
 */
class AiTextService
{
    public function __construct(
        protected AiGatewayService $gateway,
        protected TenantContextContract $tenantContext,
    ) {}

    /**
     * 聊天补全
     *
     * @param  string  $model  模型标识
     * @param  array<int, array{role: string, content: string|null}>  $messages  对话消息列表
     * @param  array<string, mixed>  $options  附加请求参数（temperature、max_tokens 等）
     * @return array<string, mixed> 标准化响应结构
     *
     * @throws RuntimeException 参数非法或上游错误时抛出
     */
    public function chat(string $model, array $messages, array $options = []): array
    {
        return $this->gateway->chat($model, $messages, $options);
    }

    /**
     * 文本补全
     *
     * @param  string  $model  模型标识
     * @param  string  $prompt  补全提示文本
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array<string, mixed> 标准化响应结构
     *
     * @throws RuntimeException 参数非法或上游错误时抛出
     */
    public function complete(string $model, string $prompt, array $options = []): array
    {
        return $this->gateway->complete($model, $prompt, $options);
    }

    /**
     * 嵌入向量生成（支持批量）
     *
     * @param  string  $model  模型标识
     * @param  string|array<int, string>  $input  单条或多条文本输入
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array<string, mixed> 标准化响应结构，data 为向量数组（维度取决于模型）
     *
     * @throws RuntimeException 参数非法或上游错误时抛出
     */
    public function embed(string $model, string|array $input, array $options = []): array
    {
        return $this->gateway->embed($model, $input, $options);
    }

    /**
     * 流式聊天补全（SSE）
     *
     * @param  string  $model  模型标识
     * @param  array<int, array{role: string, content: string|null}>  $messages  对话消息列表
     * @param  array<string, mixed>  $options  附加请求参数
     * @return \Generator<int, array<string, mixed>, void, void> 流式片段生成器
     *
     * @throws RuntimeException 流式未启用、参数非法或上游错误时抛出
     */
    public function streamChat(string $model, array $messages, array $options = []): \Generator
    {
        foreach ($this->gateway->streamChat($model, $messages, $options) as $chunk) {
            yield $chunk;
        }
    }

    /**
     * JSON 模式聊天补全
     *
     * 在 options 中注入 response_format=json_object，并将响应内容解析为数组后回填到 json 键。
     * 兼容带 markdown 代码围栏（```json ... ```）的返回。
     *
     * @param  string  $model  模型标识
     * @param  array<int, array{role: string, content: string|null}>  $messages  对话消息列表
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array<string, mixed> 标准化响应结构，额外含 json 键（解析后的数组）
     *
     * @throws RuntimeException JSON 解析失败时抛出
     */
    public function chatJson(string $model, array $messages, array $options = []): array
    {
        $options['response_format'] = ['type' => 'json_object'];

        $response = $this->chat($model, $messages, $options);

        $content = trim((string) ($response['content'] ?? ''));

        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = (string) preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(trans('ai.json_parse_failed'));
        }

        $response['json'] = $decoded;

        return $response;
    }

    /**
     * 使用默认聊天模型的聊天补全
     */
    public function chatDefault(array $messages, array $options = []): array
    {
        return $this->chat($this->defaultChatModel(), $messages, $options);
    }

    /**
     * 使用默认补全模型的文本补全
     */
    public function completeDefault(string $prompt, array $options = []): array
    {
        return $this->complete($this->defaultCompletionModel(), $prompt, $options);
    }

    /**
     * 使用默认嵌入模型的向量生成
     *
     * @param  string|array<int, string>  $input
     */
    public function embedDefault(string|array $input, array $options = []): array
    {
        return $this->embed($this->defaultEmbeddingModel(), $input, $options);
    }

    /**
     * 使用默认聊天模型的流式聊天补全
     */
    public function streamChatDefault(array $messages, array $options = []): \Generator
    {
        return $this->streamChat($this->defaultChatModel(), $messages, $options);
    }

    /**
     * 使用默认聊天模型的 JSON 模式聊天补全
     */
    public function chatJsonDefault(array $messages, array $options = []): array
    {
        return $this->chatJson($this->defaultChatModel(), $messages, $options);
    }

    // ----------------------------------------------------------------
    // 提示词管理
    // ----------------------------------------------------------------

    /**
     * 创建提示词模板（租户级，自动填充当前租户）
     *
     * @param  array{name?: string, category?: string, system_prompt?: string|null, user_prompt?: string|null, variables?: array|null, status?: string}  $data
     *
     * @throws RuntimeException 名称缺失或同租户内重名时抛出
     */
    public function createPrompt(array $data): AiPrompt
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException(trans('ai.prompt_name_required'));
        }

        if ($this->nameExistsForCurrentTenant($name)) {
            throw new RuntimeException(trans('ai.prompt_name_exists'));
        }

        // tenant_id 由 BelongsToTenant 自动填充当前租户，禁止手动指定以避免越权
        $attributes = $data;
        unset($attributes['tenant_id'], $attributes['prompt_id']);
        $attributes['name'] = $name;
        $attributes['category'] = $data['category'] ?? 'general';
        $attributes['version'] = $data['version'] ?? 1;
        $attributes['status'] = $data['status'] ?? AiPrompt::STATUS_ACTIVE;

        return AiPrompt::create($attributes);
    }

    /**
     * 更新提示词模板
     *
     * 支持 bump_version 选项：为 true 时版本号自增，用于内容变更后生成新版本。
     *
     * @param  int|string  $id  模板ID
     * @param  array<string, mixed>  $data  待更新字段
     *
     * @throws RuntimeException 模板不存在或名称冲突时抛出
     */
    public function updatePrompt(int|string $id, array $data): AiPrompt
    {
        $prompt = $this->getPrompt($id);

        if ($prompt === null) {
            throw new RuntimeException(trans('ai.prompt_not_found'));
        }

        if (isset($data['name'])) {
            $newName = trim((string) $data['name']);

            if ($newName === '') {
                throw new RuntimeException(trans('ai.prompt_name_required'));
            }

            if ($newName !== $prompt->name && $this->nameExistsForCurrentTenant($newName)) {
                throw new RuntimeException(trans('ai.prompt_name_exists'));
            }

            $data['name'] = $newName;
        }

        $bumpVersion = (bool) ($data['bump_version'] ?? false);

        unset($data['bump_version'], $data['tenant_id'], $data['prompt_id']);

        if ($bumpVersion) {
            $data['version'] = $prompt->version + 1;
        }

        $prompt->fill($data)->save();

        return $prompt;
    }

    /**
     * 删除提示词模板（系统级模板禁止删除）
     *
     * @param  int|string  $id  模板ID
     *
     * @throws RuntimeException 模板不存在或为系统级模板时抛出
     */
    public function deletePrompt(int|string $id): bool
    {
        $prompt = $this->getPrompt($id);

        if ($prompt === null) {
            throw new RuntimeException(trans('ai.prompt_not_found'));
        }

        if ($prompt->isSystemLevel()) {
            throw new RuntimeException(trans('ai.prompt_system_only'));
        }

        return (bool) $prompt->delete();
    }

    /**
     * 按ID获取提示词模板（受租户作用域过滤）
     */
    public function getPrompt(int|string $id): ?AiPrompt
    {
        return AiPrompt::find($this->normalizeId($id));
    }

    /**
     * 按名称解析提示词模板（租户级优先、系统级兜底）
     *
     * 仅返回启用状态的模板。当存在同名的租户级与系统级模板时，返回租户级（覆盖）。
     */
    public function findByName(string $name): ?AiPrompt
    {
        $tenantId = $this->currentTenantId();

        $query = AiPrompt::withoutGlobalScope(TenantScope::class)
            ->where('name', $name)
            ->where('status', AiPrompt::STATUS_ACTIVE);

        if ($tenantId !== null) {
            $query->where(function ($q) use ($tenantId): void {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $prompts = $query->get();

        if ($prompts->isEmpty()) {
            return null;
        }

        if ($tenantId !== null) {
            return $this->preferTenantLevel($prompts, $tenantId);
        }

        return $prompts->first(fn (AiPrompt $p): bool => $p->isSystemLevel()) ?? $prompts->first();
    }

    /**
     * 列出提示词模板（当前租户级 + 系统级）
     *
     * @param  string|null  $category  分类筛选，null 表示不筛选
     * @return Collection<int, AiPrompt>
     */
    public function listPrompts(?string $category = null): Collection
    {
        $tenantId = $this->currentTenantId();

        $query = AiPrompt::withoutGlobalScope(TenantScope::class)
            ->where('status', AiPrompt::STATUS_ACTIVE);

        if ($tenantId !== null) {
            $query->where(function ($q) use ($tenantId): void {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * 渲染提示词模板（替换变量占位符）
     *
     * @param  AiPrompt  $prompt  模板实例
     * @param  array<string, mixed>  $variables  变量键值对
     * @return array{system: string, user: string} 渲染后的系统提示词与用户提示词
     *
     * @throws RuntimeException 缺少必需变量时抛出
     */
    public function render(AiPrompt $prompt, array $variables): array
    {
        $this->assertRequiredVariables($prompt, $variables);

        return [
            'system' => $this->replaceVariables((string) ($prompt->system_prompt ?? ''), $variables),
            'user' => $this->replaceVariables((string) ($prompt->user_prompt ?? ''), $variables),
        ];
    }

    /**
     * 按模板名称渲染并执行聊天补全
     *
     * 解析模板 -> 渲染变量 -> 组装 system/user 消息 -> 追加额外消息 -> 调用聊天补全。
     *
     * @param  string  $name  模板名称
     * @param  array<string, mixed>  $variables  模板变量
     * @param  string  $model  模型标识
     * @param  array<int, array{role: string, content: string|null}>  $extraMessages  额外对话消息
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array<string, mixed> 标准化响应结构
     *
     * @throws RuntimeException 模板不存在、未启用或上游错误时抛出
     */
    public function chatWithPrompt(
        string $name,
        array $variables,
        string $model,
        array $extraMessages = [],
        array $options = []
    ): array {
        $prompt = $this->findByName($name);

        if ($prompt === null) {
            throw new RuntimeException(trans('ai.prompt_not_found'));
        }

        if (! $prompt->isActive()) {
            throw new RuntimeException(trans('ai.prompt_not_active'));
        }

        $rendered = $this->render($prompt, $variables);

        $messages = [];

        if ($rendered['system'] !== '') {
            $messages[] = ['role' => 'system', 'content' => $rendered['system']];
        }

        if ($rendered['user'] !== '') {
            $messages[] = ['role' => 'user', 'content' => $rendered['user']];
        }

        foreach ($extraMessages as $message) {
            $messages[] = $message;
        }

        return $this->chat($model, $messages, $options);
    }

    // ----------------------------------------------------------------
    // 内部辅助方法
    // ----------------------------------------------------------------

    /**
     * 默认聊天模型
     */
    protected function defaultChatModel(): string
    {
        return (string) config('ai.text.default_chat_model', 'gpt-4o-mini');
    }

    /**
     * 默认文本补全模型
     */
    protected function defaultCompletionModel(): string
    {
        return (string) config('ai.text.default_completion_model', 'gpt-4o-mini');
    }

    /**
     * 默认嵌入模型
     */
    protected function defaultEmbeddingModel(): string
    {
        return (string) config('ai.text.default_embedding_model', 'text-embedding-3-small');
    }

    /**
     * 当前租户ID（无租户上下文时返回 null）
     */
    protected function currentTenantId(): ?string
    {
        return $this->tenantContext->resolveId();
    }

    /**
     * 校验当前租户是否已存在同名模板（仅租户级，不与系统级冲突）
     */
    protected function nameExistsForCurrentTenant(string $name): bool
    {
        $tenantId = $this->currentTenantId();

        return AiPrompt::withoutGlobalScope(TenantScope::class)
            ->where('name', $name)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * 从候选集合中优先取租户级，回退系统级
     *
     * @param  Collection<int, AiPrompt>  $prompts
     */
    protected function preferTenantLevel(Collection $prompts, ?string $tenantId): ?AiPrompt
    {
        $tenantLevel = $prompts->first(fn (AiPrompt $p): bool => $p->tenant_id !== null
            && (string) $p->tenant_id === (string) $tenantId);

        if ($tenantLevel !== null) {
            return $tenantLevel;
        }

        return $prompts->first(fn (AiPrompt $p): bool => $p->isSystemLevel());
    }

    /**
     * 替换模板中的 {{变量}} 占位符（兼容 {{ 变量 }} 带空格写法）
     *
     * @param  array<string, mixed>  $variables
     */
    protected function replaceVariables(string $template, array $variables): string
    {
        if ($template === '') {
            return '';
        }

        foreach ($variables as $key => $value) {
            $pattern = '/\{\{\s*' . preg_quote((string) $key, '/') . '\s*\}\}/';
            $template = (string) preg_replace($pattern, (string) $value, $template);
        }

        return $template;
    }

    /**
     * 校验必需变量是否齐全
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws RuntimeException 缺少必需变量时抛出
     */
    protected function assertRequiredVariables(AiPrompt $prompt, array $variables): void
    {
        foreach (($prompt->variables ?? []) as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $name = (string) ($definition['name'] ?? '');

            if ($name === '') {
                continue;
            }

            if (! empty($definition['required']) && ! array_key_exists($name, $variables)) {
                throw new RuntimeException(trans('ai.prompt_variable_missing', ['name' => $name]));
            }
        }
    }

    /**
     * 将ID统一转换为整数
     */
    protected function normalizeId(int|string $id): int
    {
        return is_int($id) ? $id : (int) $id;
    }
}
