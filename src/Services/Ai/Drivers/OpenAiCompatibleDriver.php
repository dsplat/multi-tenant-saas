<?php

namespace MultiTenantSaas\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Services\Ai\AiResponse;
use MultiTenantSaas\Services\Ai\StreamChunk;

/**
 * OpenAI 兼容驱动
 *
 * 调用任意兼容 OpenAI Chat Completions 协议的后端
 * （OpenAI 官方、阿里百炼 compatible-mode 等）。
 *
 * provider 配置读取自 config/ai.php 的 providers 段：
 *  - base_url: API 基础地址（如 https://api.openai.com/v1）
 *  - api_key:  Bearer token
 *  - models:   可用模型列表（仅作记录，不强制校验）
 *
 * 实现非流式调用（chat / complete）与流式调用（streamChat，SSE）。
 */
class OpenAiCompatibleDriver implements AiDriverContract
{
    /**
     * AI 配置（config/ai.php）
     */
    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('ai', []);
    }

    /**
     * {@inheritDoc}
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        $provider = $this->resolveProvider($options['provider'] ?? null);
        $payload = $this->buildPayload($messages, $options);

        $data = $this->send($provider, '/chat/completions', $payload);

        return $this->parseResponse($data);
    }

    /**
     * {@inheritDoc}
     *
     * 通过 Chat Completions 端点实现单轮文本补全：
     * 将提示包装为单条 user 消息后复用 chat()。
     */
    public function complete(string $prompt, array $options = []): AiResponse
    {
        return $this->chat(
            [['role' => 'user', 'content' => $prompt]],
            $options,
        );
    }

    /**
     * {@inheritDoc}
     *
     * 通过 Chat Completions 流式端点（stream=true，SSE）逐块产出 StreamChunk：
     *  - delta.content 增量文本逐块 yield（text）
     *  - delta.tool_calls 按 index 累积，结束时一次性 yield 解析后的 toolCalls
     *    （arguments 已解码为数组，格式与 chat() 的 parseToolCalls 一致）
     *  - finish_reason 出现时随 toolCalls 一同 yield 结束块
     *
     * @return \Generator<int, StreamChunk, mixed, void>
     */
    public function streamChat(array $messages, array $options = []): \Generator
    {
        $provider = $this->resolveProvider($options['provider'] ?? null);
        $payload = $this->buildPayload($messages, $options);
        $payload['stream'] = true;

        $toolCallBuffer = [];
        $finished = false;

        foreach ($this->streamSse($provider, '/chat/completions', $payload) as $data) {
            if ($data === '[DONE]') {
                break;
            }

            $decoded = json_decode($data, true);
            if (! is_array($decoded)) {
                continue;
            }

            $choice = $decoded['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];
            $finishReason = (string) ($choice['finish_reason'] ?? '');

            if (isset($delta['content']) && $delta['content'] !== '') {
                yield new StreamChunk(text: (string) $delta['content']);
            }

            if (! empty($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $this->accumulateToolCall($toolCallBuffer, $tc);
                }
            }

            if ($finishReason !== '') {
                yield new StreamChunk(
                    toolCalls: $this->parseToolCalls(array_values($toolCallBuffer)),
                    finishReason: $finishReason,
                );
                $finished = true;
            }
        }

        // 异常断流（未见 [DONE] / finish_reason）时补发结束块，保证流可正常结束
        if (! $finished) {
            yield new StreamChunk(
                toolCalls: $this->parseToolCalls(array_values($toolCallBuffer)),
                finishReason: 'stop',
            );
        }
    }

    /**
     * 流式请求并解析 SSE 事件
     *
     * @param  array{base_url: string, api_key: string, models: array}  $provider
     * @param  string  $path  相对路径
     * @param  array  $payload  请求体（含 stream=true）
     * @return \Generator<string>  逐个产出 SSE data 字段内容（可能为 '[DONE]'）
     *
     * @throws \RuntimeException HTTP 请求失败
     */
    protected function streamSse(array $provider, string $path, array $payload): \Generator
    {
        $timeout = (int) ($this->config['timeout'] ?? 60);

        $response = Http::withToken($provider['api_key'])
            ->withOptions(['stream' => true])
            ->timeout($timeout)
            ->post($provider['base_url'].$path, $payload);

        if (! $response->successful()) {
            Log::error('[OpenAiCompatibleDriver] stream request failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            throw new \RuntimeException(
                'AI stream request to ['.$path.'] failed with status '.$response->status()
            );
        }

        $body = $response->getBody();
        $dataBuffer = '';

        foreach ($this->readLines($body) as $line) {
            $line = rtrim($line, "\r\n");

            // 空行：分派一个完整的 SSE 事件
            if ($line === '') {
                if ($dataBuffer !== '') {
                    yield $dataBuffer;
                    $dataBuffer = '';
                }
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $raw = substr($line, 5);
                // SSE 规范：data 字段值若以一个空格开头则移除该空格
                if (str_starts_with($raw, ' ')) {
                    $raw = substr($raw, 1);
                }
                if ($dataBuffer !== '') {
                    $dataBuffer .= "\n";
                }
                $dataBuffer .= $raw;
            }
            // 忽略其他 SSE 字段（event: / id: / retry: 等）与注释行
        }

        // flush 末尾未分派的事件
        if ($dataBuffer !== '') {
            yield $dataBuffer;
        }
    }

    /**
     * 从 PSR-7 流逐行读取（保持流式，避免整体缓冲）
     *
     * @param  \Psr\Http\Message\StreamInterface  $stream
     * @return \Generator<string>  逐行产出（含行尾换行符）
     */
    protected function readLines($stream): \Generator
    {
        $buffer = '';
        $chunkSize = 1024;

        while (true) {
            // 先吐出 buffer 中已就绪的完整行
            while (($pos = strpos($buffer, "\n")) !== false) {
                yield substr($buffer, 0, $pos + 1);
                $buffer = substr($buffer, $pos + 1);
            }

            if ($stream->eof()) {
                break;
            }

            $chunk = $stream->read($chunkSize);
            if ($chunk === '') {
                // 无数据且未 eof：交给下一轮 eof 检测，避免空转
                if ($stream->eof()) {
                    break;
                }
                continue;
            }
            $buffer .= $chunk;
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }

    /**
     * 累积流式 tool_calls 增量片段
     *
     * OpenAI 流式 delta 中 tool_calls 按 index 分片到达：
     *  - 首片含 id / type / function.name
     *  - 后续片仅含 function.arguments 的增量字符串
     *
     * @param  array  $buffer  按 index 索引的累积缓冲（引用传入）
     * @param  array  $delta   单块 delta.tool_calls 元素
     */
    protected function accumulateToolCall(array &$buffer, array $delta): void
    {
        $index = $delta['index'] ?? 0;

        if (! isset($buffer[$index])) {
            $buffer[$index] = [
                'id' => '',
                'type' => 'function',
                'function' => ['name' => '', 'arguments' => ''],
            ];
        }

        $entry = &$buffer[$index];

        if (! empty($delta['id'])) {
            $entry['id'] = $delta['id'];
        }
        if (! empty($delta['type'])) {
            $entry['type'] = $delta['type'];
        }

        $function = $delta['function'] ?? [];
        if (! empty($function['name'])) {
            $entry['function']['name'] = $function['name'];
        }
        if (isset($function['arguments']) && is_string($function['arguments'])) {
            $entry['function']['arguments'] .= $function['arguments'];
        }
    }

    /**
     * 构建 Chat Completions 请求体
     *
     * @param  array  $messages  消息列表
     * @param  array  $options  调用选项
     * @return array{
     *     model: string,
     *     messages: array,
     *     temperature?: float,
     *     max_tokens?: int,
     *     tools?: array,
     *     tool_choice?: string|array
     * }
     */
    protected function buildPayload(array $messages, array $options): array
    {
        $payload = [
            'model' => $options['model']
                ?? (string) ($this->config['default_model'] ?? 'gpt-4o-mini'),
            'messages' => $messages,
        ];

        if (array_key_exists('temperature', $options)) {
            $payload['temperature'] = $options['temperature'];
        }
        if (array_key_exists('max_tokens', $options)) {
            $payload['max_tokens'] = $options['max_tokens'];
        }
        if (! empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
            if (array_key_exists('tool_choice', $options)) {
                $payload['tool_choice'] = $options['tool_choice'];
            }
        }

        return $payload;
    }

    /**
     * 解析 provider 配置
     *
     * @param  string|null  $name  provider 名称（null 取 default_provider）
     * @return array{base_url: string, api_key: string, models: array}
     *
     * @throws \RuntimeException provider 未配置或缺少 api_key
     */
    protected function resolveProvider(?string $name): array
    {
        $providers = (array) ($this->config['providers'] ?? []);
        $name = $name ?: (string) ($this->config['default_provider'] ?? 'openai');

        if (! isset($providers[$name])) {
            throw new \RuntimeException("AI provider [{$name}] is not configured.");
        }

        $provider = (array) $providers[$name];

        if (empty($provider['api_key'])) {
            throw new \RuntimeException("AI provider [{$name}] has no api_key configured.");
        }

        return [
            'base_url' => rtrim((string) ($provider['base_url'] ?? ''), '/'),
            'api_key' => (string) $provider['api_key'],
            'models' => (array) ($provider['models'] ?? []),
        ];
    }

    /**
     * 发送 HTTP 请求
     *
     * @param  array{base_url: string, api_key: string, models: array}  $provider
     * @param  string  $path  相对路径（如 /chat/completions）
     * @param  array  $payload  请求体
     * @return array 响应 JSON
     *
     * @throws \RuntimeException HTTP 请求失败
     */
    protected function send(array $provider, string $path, array $payload): array
    {
        $timeout = (int) ($this->config['timeout'] ?? 60);

        $resp = Http::withToken($provider['api_key'])
            ->timeout($timeout)
            ->post($provider['base_url'].$path, $payload);

        if (! $resp->successful()) {
            // 仅记录状态码与路径，避免后端返回的敏感信息进入日志
            Log::error('[OpenAiCompatibleDriver] request failed', [
                'path' => $path,
                'status' => $resp->status(),
            ]);

            throw new \RuntimeException(
                'AI request to ['.$path.'] failed with status '.$resp->status()
            );
        }

        return (array) ($resp->json() ?? []);
    }

    /**
     * 解析 OpenAI 响应为 AiResponse
     */
    protected function parseResponse(array $data): AiResponse
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        return AiResponse::fromArray([
            'content' => $message['content'] ?? '',
            'tool_calls' => $this->parseToolCalls($message['tool_calls'] ?? []),
            'finish_reason' => $choice['finish_reason'] ?? '',
            'model' => $data['model'] ?? '',
            'usage' => $data['usage'] ?? [],
            'raw' => $data,
        ]);
    }

    /**
     * 解析 tool_calls，将 arguments JSON 字符串解码为数组
     *
     * @param  array  $toolCalls  OpenAI 原始 tool_calls 结构
     * @return array<int, array{
     *     id: string,
     *     type: string,
     *     function: array{name: string, arguments: array}
     * }>
     */
    protected function parseToolCalls(array $toolCalls): array
    {
        $parsed = [];

        foreach ($toolCalls as $call) {
            $function = $call['function'] ?? [];
            $arguments = $function['arguments'] ?? '';

            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                // 后端返回畸形 JSON arguments 时记录告警，避免静默丢失工具调用参数
                if ($arguments !== '' && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('[OpenAiCompatibleDriver] failed to decode tool_call arguments', [
                        'name' => $function['name'] ?? '',
                    ]);
                }
            } else {
                $decoded = $arguments;
            }

            $parsed[] = [
                'id' => $call['id'] ?? '',
                'type' => $call['type'] ?? 'function',
                'function' => [
                    'name' => $function['name'] ?? '',
                    'arguments' => is_array($decoded) ? $decoded : [],
                ],
            ];
        }

        return $parsed;
    }
}
