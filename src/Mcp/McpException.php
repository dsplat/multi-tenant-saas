<?php

declare(strict_types=1);

namespace MultiTenantSaas\Mcp;

use RuntimeException;

/**
 * MCP JSON-RPC 2.0 异常
 *
 * 对应 JSON-RPC 2.0 规范中的标准错误码。
 * @see https://www.jsonrpc.org/specification#error_object
 */
class McpException extends RuntimeException
{
    /** JSON-RPC 2.0 标准错误码 */
    public const CODE_PARSE_ERROR      = -32700;
    public const CODE_INVALID_REQUEST  = -32600;
    public const CODE_METHOD_NOT_FOUND = -32601;
    public const CODE_INVALID_PARAMS   = -32602;
    public const CODE_INTERNAL_ERROR   = -32603;

    /** 自定义业务错误码 */
    public const CODE_RESOURCE_NOT_FOUND = -32001;
    public const CODE_FORBIDDEN          = -32002;
    public const CODE_RATE_LIMITED       = -32003;

    /**
     * JSON-RPC 错误码
     */
    protected int $errorCode;

    /**
     * JSON-RPC 错误附加数据（可选）
     */
    protected mixed $errorData;

    public function __construct(
        string $message,
        int $errorCode = self::CODE_INTERNAL_ERROR,
        ?\Throwable $previous = null,
        mixed $errorData = null,
    ) {
        $this->errorCode = $errorCode;
        $this->errorData = $errorData;

        parent::__construct($message, 0, $previous);
    }

    /**
     * 获取 JSON-RPC 错误码
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * 获取 JSON-RPC 错误附加数据
     */
    public function getErrorData(): mixed
    {
        return $this->errorData;
    }

    /**
     * 转换为 JSON-RPC 2.0 error 对象结构
     *
     * @return array{code: int, message: string, data?: mixed}
     */
    public function toJsonRpcError(): array
    {
        $error = [
            'code'    => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->errorData !== null) {
            $error['data'] = $this->errorData;
        }

        return $error;
    }

    // ------------------------------------------------------------------
    //  工厂方法：标准 JSON-RPC 2.0 错误
    // ------------------------------------------------------------------

    /**
     * Parse error: 服务端收到无效的 JSON
     */
    public static function parseError(string $message = 'Parse error', ?\Throwable $previous = null): static
    {
        return new static($message, self::CODE_PARSE_ERROR, $previous);
    }

    /**
     * Invalid Request: 收到的 JSON 不是一个有效的请求对象
     */
    public static function invalidRequest(string $message = 'Invalid Request', ?\Throwable $previous = null): static
    {
        return new static($message, self::CODE_INVALID_REQUEST, $previous);
    }

    /**
     * Method not found: 请求的方法不存在或不可用
     */
    public static function methodNotFound(string $message = 'Method not found', ?\Throwable $previous = null): static
    {
        return new static($message, self::CODE_METHOD_NOT_FOUND, $previous);
    }

    /**
     * Invalid params: 方法参数无效
     */
    public static function invalidParams(string $message = 'Invalid params', ?\Throwable $previous = null): static
    {
        return new static($message, self::CODE_INVALID_PARAMS, $previous);
    }

    /**
     * Internal error: 服务端内部错误
     */
    public static function internalError(string $message = 'Internal error', ?\Throwable $previous = null): static
    {
        return new static($message, self::CODE_INTERNAL_ERROR, $previous);
    }

    // ------------------------------------------------------------------
    //  工厂方法：自定义业务错误
    // ------------------------------------------------------------------

    /**
     * 资源不存在
     */
    public static function resourceNotFound(string $message = 'Resource not found'): static
    {
        return new static($message, self::CODE_RESOURCE_NOT_FOUND);
    }

    /**
     * 权限不足
     */
    public static function forbidden(string $message = 'Forbidden'): static
    {
        return new static($message, self::CODE_FORBIDDEN);
    }

    /**
     * 请求频率限制
     */
    public static function rateLimited(string $message = 'Rate limited'): static
    {
        return new static($message, self::CODE_RATE_LIMITED);
    }
}
