<?php

namespace MultiTenantSaas\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Services\Ai\AiResponse;

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
 * 仅实现非流式调用；流式归 TASK-034。
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
