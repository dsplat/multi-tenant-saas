<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Drivers;

use Generator;
use Illuminate\Support\Arr;
use MultiTenantSaas\Services\Ai\AiResponse;
use MultiTenantSaas\Services\Ai\Providers\LaravelAiProviderAdapter;
use MultiTenantSaas\Services\Ai\StreamChunk;
use RuntimeException;

/**
 * Laravel AI SDK Driver 适配器
 *
 * 实现 AiDriverContract 接口，内部使用 LaravelAiProviderAdapter 调用 laravel/ai SDK。
 * 支持 OpenAI、Anthropic、Gemini、DeepSeek、Groq 等原生 provider。
 *
 * 对于需要 OpenAI 兼容模式的 provider（如 bailian、zhipu），
 * 仍应使用 ZhipuProvider 等专用实现。
 */
class LaravelAiDriverAdapter implements AiDriverContract
{
    private string $defaultProvider;
    private readonly array $resolvedConfig;

    /** @var array<string, LaravelAiProviderAdapter> */
    private array $providers = [];

    /**
     * @param array|null $config 可选；为 null 时从 config('ai') 读取
     */
    public function __construct(?array $config = null)
    {
        $this->resolvedConfig = $config ?? config('ai', []);
        $this->defaultProvider = $this->resolvedConfig['default_provider'] ?? 'openai';
    }

    public function chat(array $messages, array $options = []): AiResponse
    {
        $providerName = Arr::get($options, 'provider', $this->defaultProvider);
        $model = Arr::get($options, 'model', config('ai.default_model', 'gpt-4o-mini'));
        $provider = $this->getProvider($providerName);

        $result = $provider->chatCompletion($model, $messages, $options);

        return AiResponse::fromArray([
            'content' => $result['content'],
            'tool_calls' => $result['tool_calls'],
            'finish_reason' => $result['finish_reason'],
            'model' => $result['model'],
            'usage' => $result['usage'],
            'raw' => $result['raw'],
        ]);
    }

    public function complete(string $prompt, array $options = []): AiResponse
    {
        $providerName = Arr::get($options, 'provider', $this->defaultProvider);
        $model = Arr::get($options, 'model', config('ai.default_model', 'gpt-4o-mini'));
        $provider = $this->getProvider($providerName);

        $result = $provider->textCompletion($model, $prompt, $options);

        return AiResponse::fromArray([
            'content' => $result['text'],
            'tool_calls' => [],
            'finish_reason' => $result['finish_reason'],
            'model' => $result['model'],
            'usage' => $result['usage'],
            'raw' => $result['raw'],
        ]);
    }

    public function streamChat(array $messages, array $options = []): Generator
    {
        $providerName = Arr::get($options, 'provider', $this->defaultProvider);
        $model = Arr::get($options, 'model', config('ai.default_model', 'gpt-4o-mini'));
        $provider = $this->getProvider($providerName);

        foreach ($provider->streamChatCompletion($model, $messages, $options) as $chunk) {
            yield new StreamChunk(
                text: $chunk['content'] ?? '',
                toolCalls: $chunk['tool_calls'] ?? [],
                finishReason: $chunk['finish_reason'] ?? '',
                usage: $chunk['usage'] ?? [],
            );
        }
    }

    private function getProvider(string $name): LaravelAiProviderAdapter
    {
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        $providerConfig = $this->resolvedConfig['providers'][$name] ?? null;

        if (! $providerConfig) {
            throw new RuntimeException("Provider [{$name}] 未配置");
        }

        if (! isset($providerConfig['driver'])) {
            $providerConfig['driver'] = $name;
        }

        $provider = new LaravelAiProviderAdapter($providerConfig);

        if (! $provider->isAvailable()) {
            throw new RuntimeException("Provider [{$name}] 不可用（缺少 API Key）");
        }

        $this->providers[$name] = $provider;

        return $provider;
    }

    public function getDefaultModel(): string
    {
        return $this->resolvedConfig['default_model'] ?? config('ai.default_model', 'gpt-4o-mini');
    }

    public function getDefaultProvider(): string
    {
        return $this->defaultProvider;
    }

    /** @return string[] */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->resolvedConfig['providers'] ?? []);
    }

    public function getConfig(): array
    {
        return $this->resolvedConfig;
    }
}
