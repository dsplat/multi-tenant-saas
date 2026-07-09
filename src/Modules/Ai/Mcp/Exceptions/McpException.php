<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp\Exceptions;

use RuntimeException;

class McpException extends RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public const SERVER_ERROR_MIN = -32000;
    public const SERVER_ERROR_MAX = -32099;

    public const TOOL_NOT_FOUND = -31000;
    public const TENANT_UNAUTHORIZED = -31001;
    public const RATE_LIMITED = -31002;
    public const TOOL_EXECUTION_FAILED = -31003;

    protected int $errorCode;
    protected mixed $errorData;

    public function __construct(
        string $message = '',
        int $code = self::INTERNAL_ERROR,
        mixed $data = null,
        ?\Throwable $previous = null,
    ) {
        $this->errorCode = $code;
        $this->errorData = $data;

        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getErrorData(): mixed
    {
        return $this->errorData;
    }

    /**
     * @return array{jsonrpc: string, error: array{code: int, message: string, data: mixed}, id: string|null}
     */
    public function toJsonRpc(?string $id = null): array
    {
        $response = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
            'id' => $id,
        ];

        if ($this->errorData !== null) {
            $response['error']['data'] = $this->errorData;
        }

        return $response;
    }

    public static function parseError(?string $data = null): static
    {
        return new static('Parse error', self::PARSE_ERROR, $data);
    }

    public static function invalidRequest(?string $data = null): static
    {
        return new static('Invalid request', self::INVALID_REQUEST, $data);
    }

    public static function methodNotFound(string $method): static
    {
        return new static("Method not found: {$method}", self::METHOD_NOT_FOUND, $method);
    }

    public static function invalidParams(?string $data = null): static
    {
        return new static('Invalid params', self::INVALID_PARAMS, $data);
    }

    public static function internalError(?string $data = null): static
    {
        return new static('Internal error', self::INTERNAL_ERROR, $data);
    }

    public static function serverError(string $message, int $code = -32000, mixed $data = null): static
    {
        $code = max(self::SERVER_ERROR_MIN, min(self::SERVER_ERROR_MAX, $code));

        return new static($message, $code, $data);
    }
}
