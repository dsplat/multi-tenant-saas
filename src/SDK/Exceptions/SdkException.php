<?php

namespace MultiTenantSaas\SDK\Exceptions;

use RuntimeException;
use Throwable;

/**
 * SDK 异常
 *
 * 封装 API 调用过程中的错误，携带 HTTP 状态码、错误码与原始响应体，
 * 便于调用方进行错误分类处理与日志记录。
 */
class SdkException extends RuntimeException
{
    /**
     * @param  string  $message  错误信息
     * @param  int  $statusCode  HTTP 状态码（连接错误时为 0）
     * @param  string  $responseBody  原始响应体
     * @param  string|null  $errorCode  业务错误码
     * @param  array<string, mixed>  $context  附加上下文（请求路径等）
     * @param  Throwable|null  $previous  前置异常
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly string $responseBody = '',
        private readonly ?string $errorCode = null,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * 是否为客户端错误（4xx）
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * 是否为服务端错误（5xx）
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * 是否为连接错误（无 HTTP 响应）
     */
    public function isConnectionError(): bool
    {
        return $this->statusCode === 0;
    }
}
