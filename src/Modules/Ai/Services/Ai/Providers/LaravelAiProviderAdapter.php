<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Providers;

use Generator;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use MultiTenantSaas\Contracts\AiProviderContract;
use MultiTenantSaas\Exceptions\DomainException;
use Throwable;

/**
 * Laravel AI SDK Provider 适配器
 *
 * 实现 AiProviderContract 接口。
 *
 * 工具调用路径：当 options 含 tools 时，绕过 laravel/ai SDK 直接调用
 * OpenAI 兼容 /chat/completions API（SDK 会自动执行工具，与 AgentRuntime
 * 的 ReAct 循环冲突）。无工具时仍走 SDK 的 AnonymousAgent。
 */
class LaravelAiProviderAdapter implements AiProviderContract
{
    private string $labProvider;

    /**
     * @param  array{driver: string, key?: string, url?: string, base_url?: string, api_key?: string}  $config
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

    /** 解析 API base URL（兼容多种配置字段） */
    private function resolveBaseUrl(): string
    {
        $url = $this->config['url']
            ?? $this->config['base_url']
            ?? 'https://api.openai.com/v1';

        return rtrim($url, '/');
    }

    /** 解析 API Key */
    private function resolveApiKey(): string
    {
        return $this->config['key']
            ?? $this->config['api_key']
            ?? '';
    }

    public function chatCompletion(string $model, array $messages, array $options = []): array
    {
        $timeout = $options['timeout'] ?? config('ai.timeout', 60);

        // 有工具定义 → 走原生 OpenAI 兼容 API（SDK 会自动执行工具，与 ReAct 循环冲突）
        if (! empty($options['tools'])) {
            return $this->rawChatCompletion($model, $messages, $options, $timeout);
        }

        [$instructions, $history] = $this->parseMessages($messages);

        try {
            $agent = $this->buildAgent($history, $instructions);

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
            throw new DomainException(
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
            throw new DomainException(
                "Laravel AI SDK [{$this->labProvider}] embeddings 请求失败: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function streamChatCompletion(string $model, array $messages, array $options = []): Generator
    {
        $timeout = $options['timeout'] ?? config('ai.timeout', 60);

        // 有工具定义 → 走原生 OpenAI 兼容 SSE API
        if (! empty($options['tools'])) {
            yield from $this->rawStreamChatCompletion($model, $messages, $options, $timeout);

            return;
        }

        [$instructions, $history] = $this->parseMessages($messages);

        try {
            $agent = $this->buildAgent($history, $instructions);

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
            throw new DomainException(
                "Laravel AI SDK [{$this->labProvider}] 流式请求失败: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    // ─── 原生 OpenAI 兼容 API（工具调用路径）───────────────────────────

    /**
     * 非流式 chat/completions（含 tools）。
     *
     * 直接调用 OpenAI 兼容端点，返回标准格式（含 tool_calls），
     * 由 AgentRuntime ReAct 循环执行工具。
     */
    private function rawChatCompletion(string $model, array $messages, array $options, int $timeout): array
    {
        $body = $this->buildRawRequestBody($model, $messages, $options, false);

        $response = Http::withToken($this->resolveApiKey())
            ->timeout($timeout)
            ->post($this->resolveBaseUrl() . '/chat/completions', $body);

        if ($response->failed()) {
            throw new DomainException(
                "OpenAI API 请求失败 [{$response->status()}]: " . $response->body()
            );
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $msg = $choice['message'] ?? [];

        return [
            'id' => $data['id'] ?? null,
            'object' => 'chat.completion',
            'model' => $data['model'] ?? $model,
            'content' => $msg['content'] ?? '',
            'role' => 'assistant',
            'tool_calls' => $msg['tool_calls'] ?? null,
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
            'usage' => [
                'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $data['usage']['total_tokens'] ?? 0,
            ],
            'raw' => $data,
        ];
    }

    /**
     * 流式 chat/completions（含 tools）。
     *
     * 逐行解析 SSE（data: {...}\n\n），yield 标准 chunk 格式。
     * tool_calls 通过 delta 增量拼接，在 finish_reason 非 null 时一次性输出。
     */
    private function rawStreamChatCompletion(string $model, array $messages, array $options, int $timeout): Generator
    {
        $body = $this->buildRawRequestBody($model, $messages, $options, true);

        $response = Http::withToken($this->resolveApiKey())
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->timeout($timeout)
            ->withOptions(['stream' => true])
            ->post($this->resolveBaseUrl() . '/chat/completions', $body);

        if ($response->failed()) {
            throw new DomainException(
                "OpenAI API 流式请求失败 [{$response->status()}]: " . $response->body()
            );
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        // 累积 tool_calls delta（按 index 拼接）
        $toolCallAccum = [];

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            // 按行切分
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '[DONE]') {
                    // 流结束：输出累积的 tool_calls
                    if (! empty($toolCallAccum)) {
                        yield [
                            'id' => null,
                            'object' => 'chat.completion.chunk',
                            'model' => $model,
                            'content' => '',
                            'role' => 'assistant',
                            'tool_calls' => $this->finalizeToolCalls($toolCallAccum),
                            'finish_reason' => 'tool_calls',
                            'usage' => null,
                            'raw' => null,
                        ];
                    }

                    return;
                }

                $chunk = json_decode($payload, true);
                if (! $chunk) {
                    continue;
                }

                $delta = $chunk['choices'][0]['delta'] ?? [];
                $finishReason = $chunk['choices'][0]['finish_reason'] ?? null;

                // 文本增量
                if (! empty($delta['content'])) {
                    yield [
                        'id' => $chunk['id'] ?? null,
                        'object' => 'chat.completion.chunk',
                        'model' => $chunk['model'] ?? $model,
                        'content' => $delta['content'],
                        'role' => 'assistant',
                        'tool_calls' => null,
                        'finish_reason' => null,
                        'raw' => $chunk,
                    ];
                }

                // tool_calls delta 累积
                if (! empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (! isset($toolCallAccum[$idx])) {
                            $toolCallAccum[$idx] = [
                                'id' => $tc['id'] ?? '',
                                'type' => 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }
                        if (! empty($tc['id'])) {
                            $toolCallAccum[$idx]['id'] = $tc['id'];
                        }
                        if (! empty($tc['function']['name'])) {
                            $toolCallAccum[$idx]['function']['name'] .= $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCallAccum[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }

                // finish_reason 非 null 且无 tool_calls → 正常结束
                if ($finishReason !== null && empty($toolCallAccum)) {
                    yield [
                        'id' => $chunk['id'] ?? null,
                        'object' => 'chat.completion.chunk',
                        'model' => $chunk['model'] ?? $model,
                        'content' => '',
                        'role' => 'assistant',
                        'tool_calls' => null,
                        'finish_reason' => $finishReason,
                        'usage' => $chunk['usage'] ?? null,
                        'raw' => $chunk,
                    ];
                }
            }
        }
    }

    /** 构建 OpenAI 兼容请求体 */
    private function buildRawRequestBody(string $model, array $messages, array $options, bool $stream): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        return $body;
    }

    /** 将累积的 tool_calls delta 转为最终数组 */
    private function finalizeToolCalls(array $accum): array
    {
        ksort($accum);

        return array_values($accum);
    }

    /**
     * 解析消息数组：分离 system 角色为 instructions，其余转为 Message 对象。
     *
     * laravel/ai MessageRole 枚举仅支持 assistant/user/tool_result，
     * system 角色必须作为 AnonymousAgent 的 instructions 参数传入。
     *
     * @return array{0: string, 1: Message[]}
     */
    protected function parseMessages(array $messages): array
    {
        $instructions = '';
        $history = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if ($role === 'system') {
                // system 消息拼接为 instructions
                $instructions .= ($instructions !== '' ? "\n\n" : '') . $content;

                continue;
            }

            // tool 角色映射为 SDK 支持的 tool_result
            if ($role === 'tool') {
                $role = 'tool_result';
            }

            $history[] = new Message($role, $content);
        }

        return [$instructions, $history];
    }

    /**
     * Build an anonymous Agent with the given message history.
     *
     * laravel/ai ^0.8 使用 AnonymousAgent(instructions, messages, tools)。
     *
     * @param  Message[]  $history
     */
    protected function buildAgent(array $history, string $instructions = ''): AnonymousAgent
    {
        return new AnonymousAgent($instructions, $history, []);
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
