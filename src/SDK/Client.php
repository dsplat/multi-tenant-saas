<?php

namespace MultiTenantSaas\SDK;

use MultiTenantSaas\SDK\Exceptions\SdkException;
use MultiTenantSaas\SDK\Resources\AiResource;
use MultiTenantSaas\SDK\Resources\PaymentResource;
use MultiTenantSaas\SDK\Resources\TenantResource;

/**
 * 多租户 SaaS PHP SDK 客户端
 *
 * 独立于 Laravel 框架，仅依赖 PHP 8.2+ 与 ext-curl。
 *
 * 链式调用示例：
 * <code>
 * $client = new Client('https://api.example.com', 'sk_xxx', ['retries' => 3]);
 * $tenant = $client->tenant()->find(1001);
 * $order  = $client->payment()->createOrder([...]);
 * $text   = $client->ai()->textCompletion([...]);
 * </code>
 *
 * 鉴权：通过 Authorization: Bearer <apiKey> 头部携带 API Key。
 * 重试：对 5xx 响应与连接错误自动重试，退避延迟递增；4xx 不重试。
 */
class Client
{
    /** 默认请求超时（秒） */
    public const DEFAULT_TIMEOUT = 30;

    /** 默认最大重试次数（不含首次请求） */
    public const DEFAULT_RETRIES = 3;

    /** 默认重试基础退避（毫秒），实际延迟 = base * attempt */
    public const DEFAULT_RETRY_BASE_DELAY_MS = 500;

    /** API 版本前缀 */
    public const DEFAULT_API_PREFIX = '/v1';

    private TenantResource $tenantResource;

    private PaymentResource $paymentResource;

    private AiResource $aiResource;

    /**
     * @param  string  $baseUrl  API 根地址
     * @param  string  $apiKey  API Key
     * @param  array<string, mixed>  $options  选项：
     *                                         - timeout: int            请求超时秒数（默认 30）
     *                                         - retries: int            最大重试次数（默认 3）
     *                                         - retry_base_delay_ms: int 重试基础退避毫秒（默认 500）
     *                                         - api_prefix: string       API 路径前缀（默认 /v1）
     *                                         - http_handler: callable|null 自定义 HTTP 处理器（用于测试）
     *                                         签名: fn(string $method, string $url, array $headers, string $body): array{status:int, body:string, error:?string}
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly array $options = [],
    ) {
        $this->tenantResource = new TenantResource($this);
        $this->paymentResource = new PaymentResource($this);
        $this->aiResource = new AiResource($this);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * 租户管理资源
     */
    public function tenant(): TenantResource
    {
        return $this->tenantResource;
    }

    /**
     * 支付资源
     */
    public function payment(): PaymentResource
    {
        return $this->paymentResource;
    }

    /**
     * AI 资源
     */
    public function ai(): AiResource
    {
        return $this->aiResource;
    }

    /**
     * 发起 API 请求并返回解码后的响应数据
     *
     * @param  string  $method  HTTP 方法
     * @param  string  $path  相对路径（不含 API 前缀）
     * @param  array<string, mixed>  $query  查询参数
     * @param  array<string, mixed>|null  $body  JSON 请求体
     * @return array<string, mixed> 解码后的 JSON 响应
     *
     * @throws SdkException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->buildUrl($path, $query);
        $headers = $this->buildHeaders();
        $bodyString = $body !== null ? (json_encode($body, JSON_UNESCAPED_UNICODE) ?: '') : '';
        if ($body !== null && $bodyString === '') {
            throw new SdkException('请求体 JSON 编码失败', 0, '', 'json_encode_failed', ['path' => $path]);
        }

        $response = $this->executeWithRetry($method, $url, $headers, $bodyString);

        return $this->parseResponse($response, $path);
    }

    /**
     * 构建完整 URL（含查询串）
     */
    private function buildUrl(string $path, array $query): string
    {
        $prefix = $this->options['api_prefix'] ?? self::DEFAULT_API_PREFIX;
        $normalizedPath = '/' . ltrim($path, '/');
        $url = rtrim($this->baseUrl, '/') . $prefix . $normalizedPath;

        if (! empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * 构建请求头（含鉴权）
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'MultiTenantSaas-PHP-SDK/1.0',
        ];
    }

    /**
     * 带重试的请求执行
     *
     * 对连接错误与 5xx 响应进行重试，退避延迟随次数递增。
     *
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string, error:?string}
     *
     * @throws SdkException
     */
    private function executeWithRetry(string $method, string $url, array $headers, string $body): array
    {
        $maxRetries = (int) ($this->options['retries'] ?? self::DEFAULT_RETRIES);
        $baseDelayMs = (int) ($this->options['retry_base_delay_ms'] ?? self::DEFAULT_RETRY_BASE_DELAY_MS);

        $attempt = 0;
        $lastResponse = null;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            $response = $this->callHttp($method, $url, $headers, $body);
            $lastResponse = $response;

            // 成功：2xx
            if ($response['status'] >= 200 && $response['status'] < 300) {
                return $response;
            }

            // 连接错误：可重试
            $isConnectionError = $response['error'] !== null && $response['error'] !== '';
            // 服务端错误：可重试
            $isServerError = $response['status'] >= 500;

            if (! $isConnectionError && ! $isServerError) {
                // 客户端错误（4xx）：不重试，直接返回交由上层解析抛出
                return $response;
            }

            $lastError = $response['error'] ?? ('HTTP ' . $response['status']);

            if ($attempt < $maxRetries) {
                // 退避延迟（毫秒 -> 微秒）
                $delayMs = $baseDelayMs * ($attempt + 1);
                usleep($delayMs * 1000);
            }

            $attempt++;
        }

        // 重试耗尽，仍失败
        $status = $lastResponse['status'] ?? 0;
        $bodyStr = $lastResponse['body'] ?? '';
        $message = $lastError ?? '请求失败';

        throw new SdkException(
            $message,
            $status,
            $bodyStr,
            $status >= 500 ? 'server_error' : 'connection_error',
            ['url' => $url, 'method' => $method, 'attempts' => $attempt],
        );
    }

    /**
     * 调用 HTTP 处理器（默认 curl，可注入自定义处理器用于测试）
     *
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string, error:?string}
     */
    private function callHttp(string $method, string $url, array $headers, string $body): array
    {
        $handler = $this->options['http_handler'] ?? null;

        if (is_callable($handler)) {
            $result = $handler($method, $url, $headers, $body);

            return [
                'status' => (int) ($result['status'] ?? 0),
                'body' => (string) ($result['body'] ?? ''),
                'error' => $result['error'] ?? null,
            ];
        }

        return $this->curlExecute($method, $url, $headers, $body);
    }

    /**
     * curl 默认实现
     *
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string, error:?string}
     */
    private function curlExecute(string $method, string $url, array $headers, string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($this->options['timeout'] ?? self::DEFAULT_TIMEOUT));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $errorMessage = $errno ? (curl_error($ch) ?: curl_strerror($errno)) : null;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $response === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => $errorMessage ?? 'curl request failed',
            ];
        }

        return [
            'status' => $statusCode,
            'body' => (string) $response,
            'error' => null,
        ];
    }

    /**
     * 解析响应：解码 JSON，校验状态码
     *
     * @param  array{status:int, body:string, error:?string}  $response
     * @return array<string, mixed>
     *
     * @throws SdkException
     */
    private function parseResponse(array $response, string $path): array
    {
        $status = $response['status'];
        $body = $response['body'];

        $data = json_decode($body, true);
        if (! is_array($data)) {
            // 非 JSON 响应
            if ($status >= 200 && $status < 300) {
                return ['raw' => $body];
            }

            throw new SdkException(
                '响应体非 JSON 格式',
                $status,
                $body,
                'invalid_json',
                ['path' => $path],
            );
        }

        if ($status >= 400) {
            $message = is_string($data['message'] ?? null) ? $data['message'] : '请求失败';
            $errorCode = is_string($data['error_code'] ?? null) ? $data['error_code'] : null;

            throw new SdkException(
                $message,
                $status,
                $body,
                $errorCode,
                ['path' => $path],
            );
        }

        return $data;
    }
}
