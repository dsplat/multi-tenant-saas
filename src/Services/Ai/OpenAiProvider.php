<?php

namespace MultiTenantSaas\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiProviderContract;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * OpenAI 提供商
 *
 * 实现 AiProviderContract，适配 OpenAI 官方 API：
 *  - 模型：GPT-4o / GPT-4o-mini / GPT-4-turbo
 *  - 鉴权：Bearer API Key
 *  - 端点：chat/completions、completions、embeddings
 *  - 流式：SSE（Server-Sent Events）解析
 *  - 超时：从 config('ai.providers.openai.timeout') 读取
 *
 * 配置来源：config('ai.providers.openai.*')
 * 不直接依赖 AiGatewayService，仅保证方法签名匹配 Contract。
 */
class OpenAiProvider implements AiProviderContract
{
    /**
     * 默认 API 基础地址
     */
    protected const BASE_URL = 'https://api.openai.com/v1';

    /**
     * 端点路径
     */
    protected const CHAT_ENDPOINT = '/chat/completions';

    protected const EMBEDDINGS_ENDPOINT = '/embeddings';

    /**
     * 支持的模型列表
     */
    protected const SUPPORTED_MODELS = [
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4-turbo',
    ];

    /**
     * 读取提供商配置
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("ai.providers.openai.{$key}", $default);
    }

    /**
     * 获取 API Key
     *
     * @throws \RuntimeException 配置缺失时抛出
     */
    protected function getApiKey(): string
    {
        $key = (string) $this->config('api_key', '');

        if ($key === '') {
            throw new \RuntimeException(trans('ai.provider_not_configured', ['provider' => 'openai']));
        }

        return $key;
    }

    /**
     * 获取基础地址（支持配置覆盖）
     */
    protected function getBaseUrl(): string
    {
        $url = (string) $this->config('base_url', self::BASE_URL);

        return rtrim($url, '/');
    }

    /**
     * 获取请求超时秒数
     */
    protected function getTimeout(): int
    {
        return (int) $this->config('timeout', 30);
    }

    /**
     * 构建带鉴权与超时的 HTTP 请求实例
     */
    protected function http(): PendingRequest
    {
        return Http::withToken($this->getApiKey())
            ->asJson()
            ->timeout($this->getTimeout());
    }

    /**
     * 校验模型是否被支持
     *
     * @throws \RuntimeException 模型不支持时抛出
     */
    protected function assertModelSupported(string $model): void
    {
        if (! in_array($model, self::SUPPORTED_MODELS, true)) {
            throw new \RuntimeException(trans('ai.model_not_supported', [
                'provider' => 'openai',
                'model' => $model,
            ]));
        }
    }

    /**
     * 根据 HTTP 响应映射错误码并抛出异常
     *
     * @param  string  $operation  调用方法名（用于日志）
     * @param  string  $model  模型名
     *
     * @throws \RuntimeException 始终抛出
     */
    protected function throwHttpError(Response $response, string $operation, string $model): void
    {
        $status = $response->status();
        $body = (string) $response->body();

        $errorKey = match (true) {
            $status === 401 => 'ai.provider_auth_failed',
            $status === 403 => 'ai.provider_permission_denied',
            $status === 404 => 'ai.provider_not_found',
            $status === 408 => 'ai.provider_timeout',
            $status === 413 => 'ai.provider_request_too_large',
            $status === 429 => 'ai.provider_rate_limited',
            $status >= 500 => 'ai.provider_server_error',
            default => 'ai.provider_api_error',
        };

        Log::error('[OpenAiProvider] '.$operation.' HTTP error', [
            'model' => $model,
            'status' => $status,
            'body' => $body,
        ]);

        throw new \RuntimeException(trans($errorKey, ['provider' => 'openai']).' ['.$status.']');
    }

    /**
     * {@inheritdoc}
     */
    public function chatCompletion(string $model, array $messages, array $options = []): array
    {
        $this->assertModelSupported($model);

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
        ], $options);

        try {
            $response = $this->http()->post($this->getBaseUrl().self::CHAT_ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            Log::error('[OpenAiProvider] chatCompletion connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'openai']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'chatCompletion', $model);
        } catch (Throwable $e) {
            Log::error('[OpenAiProvider] chatCompletion exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'openai']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, 'chatCompletion', $model);
        }

        $data = $response->json() ?? [];
        $choice = $data['choices'][0] ?? [];

        return [
            'id' => $data['id'] ?? null,
            'object' => $data['object'] ?? null,
            'model' => $data['model'] ?? $model,
            'role' => 'assistant',
            'content' => $choice['message']['content'] ?? '',
            'tool_calls' => $choice['message']['tool_calls'] ?? null,
            'finish_reason' => $choice['finish_reason'] ?? null,
            'usage' => $data['usage'] ?? [],
            'raw' => $data,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * GPT-4o 系列不再支持已废弃的 /v1/completions 端点，这里将文本补全请求
     * 包装为单轮 user 消息，复用 chatCompletion 走 chat/completions 路径，
     * 再将响应映射为 textCompletion 契约结构（text 字段）。
     */
    public function textCompletion(string $model, string $prompt, array $options = []): array
    {
        $this->assertModelSupported($model);

        $response = $this->chatCompletion(
            $model,
            [['role' => 'user', 'content' => $prompt]],
            $options
        );

        return [
            'id' => $response['id'] ?? null,
            'object' => $response['object'] ?? null,
            'model' => $response['model'] ?? $model,
            'text' => $response['content'] ?? '',
            'finish_reason' => $response['finish_reason'] ?? null,
            'usage' => $response['usage'] ?? [],
            'raw' => $response['raw'] ?? [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function embeddings(string $model, string|array $input, array $options = []): array
    {
        $payload = array_merge([
            'model' => $model,
            'input' => $input,
        ], $options);

        try {
            $response = $this->http()->post($this->getBaseUrl().self::EMBEDDINGS_ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            Log::error('[OpenAiProvider] embeddings connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'openai']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'embeddings', $model);
        } catch (Throwable $e) {
            Log::error('[OpenAiProvider] embeddings exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'openai']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, 'embeddings', $model);
        }

        $data = $response->json() ?? [];

        $vectors = [];
        foreach (($data['data'] ?? []) as $item) {
            $vectors[] = [
                'index' => $item['index'] ?? null,
                'embedding' => $item['embedding'] ?? [],
                'object' => $item['object'] ?? null,
            ];
        }

        return [
            'model' => $data['model'] ?? $model,
            'object' => $data['object'] ?? null,
            'data' => $vectors,
            'usage' => $data['usage'] ?? [],
            'raw' => $data,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function streamChatCompletion(string $model, array $messages, array $options = []): \Generator
    {
        $this->assertModelSupported($model);

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
        ], $options);

        try {
            $response = $this->http()
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => $this->getTimeout(),
                ])
                ->post($this->getBaseUrl().self::CHAT_ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            Log::error('[OpenAiProvider] streamChatCompletion connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'openai']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'streamChatCompletion', $model);
        } catch (Throwable $e) {
            Log::error('[OpenAiProvider] streamChatCompletion exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'openai']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, 'streamChatCompletion', $model);
        }

        foreach ($this->parseSseStream($response) as $chunk) {
            yield $chunk;
        }
    }

    /**
     * 解析 SSE 流，逐块产出标准化片段
     *
     * OpenAI 流式响应每行形如 `data: {json}`，并以 `data: [DONE]` 结束。
     *
     * @return \Generator<int, array<string, mixed>, void, void>
     */
    protected function parseSseStream(Response $response): \Generator
    {
        foreach ($this->readSseLines($response) as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]' || $data === '') {
                continue;
            }

            $json = json_decode($data, true);
            if (! is_array($json)) {
                continue;
            }

            $choice = $json['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];

            yield [
                'id' => $json['id'] ?? null,
                'object' => $json['object'] ?? null,
                'model' => $json['model'] ?? null,
                'content' => $delta['content'] ?? '',
                'role' => $delta['role'] ?? null,
                'tool_calls' => $delta['tool_calls'] ?? null,
                'finish_reason' => $choice['finish_reason'] ?? null,
                'raw' => $json,
            ];
        }
    }

    /**
     * 逐行读取 SSE 响应，优先使用流式资源，回退到完整 body
     *
     * @return \Generator<int, string, void, void>
     */
    protected function readSseLines(Response $response): \Generator
    {
        $body = $response->getBody();

        if ($body instanceof StreamInterface) {
            $buffer = '';
            while (! $body->eof()) {
                $buffer .= $body->read(1024);
                while (($eol = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $eol);
                    $buffer = substr($buffer, $eol + 1);
                    $line = rtrim($line, "\r");
                    if ($line !== '') {
                        yield $line;
                    }
                }
            }
            if (rtrim($buffer, "\r\n") !== '') {
                yield rtrim($buffer, "\r\n");
            }

            return;
        }

        $content = (string) $response->body();
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line !== '') {
                yield $line;
            }
        }
    }
}
