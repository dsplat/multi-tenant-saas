<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Providers;

use Generator;
use Laravel\Ai\Agent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use MultiTenantSaas\Contracts\AiProviderContract;
use Throwable;

/**
 * Laravel AI SDK Provider 适配器
 *
 * 实现 AiProviderContract 接口，内部使用 laravel/ai SDK 调用各 provider。
 * 支持 OpenAI、Anthropic、Gemini、DeepSeek、Groq 等原生 provider。
 *
 * 对于需要 OpenAI 兼容模式的 provider（如 bailian、zhipu），
 * 仍应使用 ZhipuProvider 等专用实现。
 */
class LaravelAiProviderAdapter implements AiProviderContract
{
    private string $labProvider;

    /**
     * @param array{driver: string, key?: string, url?: string, base_url?: string, api_key?: string} $config
     */
    public function __construct(
        private readonly array $config,
    ) {
        $this->labProvider = $this->resolveLabProvider($config['driver'] ?? 'openai');
    }

    private function resolveLabProvider(string $driver): string
    {
        return match ($driver) {
            'openai' => Lab::OpenAI->value,
            'anthropic' => Lab::Anthropic->value,
            'gemini' => Lab::Gemini->value,
            'deepseek' => Lab::DeepSeek->value,
            'groq' => Lab::Groq->value,
            default => $driver,
        };
    }

    public function chatCompletion(string $model, array $messages, array $options = []): array
    {
        $timeout = $options['timeout'] ?? config('ai.timeout', 60);

        $history = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $history[] = new Message($role, $content);
        }

        try {
            $agent = $this->buildAgent($history);

            $response = $agent->prompt(
                prompt: '',
                provider: $this->labProvider,
                model: $model,
                timeout: $timeout,
            );

            $content = (string) $response;
            $usageData = $response->usage;

            $usage = [
                'prompt_tokens' => $usageData->promptTokens ?? 0,
                'completion_tokens' => $usageData->completionTokens ?? 0,
                'total_tokens' => ($usageData->promptTokens ?? 0) + ($usageData->completionTokens ?? 0),
                'cache_read_input_tokens' => $usageData->cacheReadInputTokens ?? 0,
                'cache_creation_input_tokens' => $usageData->cacheWriteInputTokens ?? 0,
            ];

            return [
                'id' => $response->invocationId ?? null,
                'object' => 'chat.completion',
                'model' => $model,
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => null,
                'finish_reason' => 'stop',
                'usage' => $usage,
                'raw' => ['response' => $response],
            ];
        } catch (Throwable $e) {
            throw new \RuntimeException(
                "Laravel AI SDK [{$this->labProvider}] 请求失败: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function textCompletion(string $model, string $prompt, array $options = []): array
    {
        $result = $this->chatCompletion($model, [
            ['role' => 'user', 'content' => $prompt],
        ], $options);

        return [
            'id' => $result['id'],
            'object' => 'text_completion',
            'model' => $model,
            'text' => $result['content'],
            'finish_reason' => $result['finish_reason'],
            'usage' => $result['usage'],
            'raw' => $result['raw'],
        ];
    }

    public function embeddings(string $model, string|array $input, array $options = []): array
    {
        $inputs = is_array($input) ? $input : [$input];

        try {
            $response = Embeddings::for($inputs)
                ->provider($this->labProvider)
                ->model($model)
                ->generate();

            $data = [];
            foreach ($response->embeddings as $index => $embedding) {
                $data[] = [
                    'index' => $index,
                    'embedding' => $embedding,
                    'object' => 'embedding',
                ];
            }

            return [
                'model' => $model,
                'object' => 'list',
                'data' => $data,
                'usage' => [
                    'prompt_tokens' => $response->tokens ?? 0,
                    'total_tokens' => $response->tokens ?? 0,
                ],
                'raw' => ['response' => $response],
            ];
        } catch (Throwable $e) {
            throw new \RuntimeException(
                "Laravel AI SDK [{$this->labProvider}] embeddings 请求失败: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function streamChatCompletion(string $model, array $messages, array $options = []): Generator
    {
        $timeout = $options['timeout'] ?? config('ai.timeout', 60);

        $history = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $history[] = new Message($role, $content);
        }

        try {
            $agent = $this->buildAgent($history);

            /** @var StreamableAgentResponse $stream */
            $stream = $agent->stream(
                prompt: '',
                provider: $this->labProvider,
                model: $model,
                timeout: $timeout,
            );

            $toolCalls = [];

            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    yield [
                        'id' => $event->id,
                        'object' => 'chat.completion.chunk',
                        'model' => $model,
                        'content' => $event->delta,
                        'role' => 'assistant',
                        'tool_calls' => null,
                        'finish_reason' => null,
                        'raw' => $event->toArray(),
                    ];
                } elseif ($event instanceof ToolCallEvent) {
                    $toolCalls[] = [
                        'id' => $event->toolCall->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $event->toolCall->name,
                            'arguments' => $event->toolCall->arguments,
                        ],
                    ];
                } elseif ($event instanceof StreamEnd) {
                    // Yield final chunk with finish_reason and any accumulated tool calls
                    $usage = [
                        'prompt_tokens' => $event->usage->promptTokens ?? 0,
                        'completion_tokens' => $event->usage->completionTokens ?? 0,
                        'total_tokens' => ($event->usage->promptTokens ?? 0) + ($event->usage->completionTokens ?? 0),
                    ];

                    yield [
                        'id' => $event->id,
                        'object' => 'chat.completion.chunk',
                        'model' => $model,
                        'content' => '',
                        'role' => 'assistant',
                        'tool_calls' => $toolCalls ?: null,
                        'finish_reason' => $event->reason,
                        'usage' => $usage,
                        'raw' => $event->toArray(),
                    ];
                }
            }
        } catch (Throwable $e) {
            throw new \RuntimeException(
                "Laravel AI SDK [{$this->labProvider}] 流式请求失败: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Build an anonymous Agent with the given message history.
     *
     * @param  Message[]  $history
     */
    protected function buildAgent(array $history): Agent
    {
        return new class($history) extends Agent
        {
            public function __construct(private array $history) {}

            public function instructions(): string
            {
                return '';
            }

            public function messages(): iterable
            {
                return $this->history;
            }
        };
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isAvailable(): bool
    {
        $key = $this->config['key'] ?? $this->config['api_key'] ?? '';

        return ! empty($key);
    }
}
