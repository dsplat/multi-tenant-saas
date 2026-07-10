<?php

namespace MultiTenantSaas\Exceptions;

use Exception;
use Throwable;

/**
 * MCP (Model Context Protocol) 异常
 *
 * 对应 JSON-RPC 2.0 标准错误码：
 * -32700: Parse error
 * -32600: Invalid Request
 * -32601: Method not found
 * -32602: Invalid params
 * -32603: Internal error
 * -32000~-32099: Server error (reserved)
 */
class McpException extends Exception
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    public const AUTH_REQUIRED = -32001;

    public const TOKEN_EXPIRED = -32002;

    public const TOOL_NOT_FOUND = -32003;

    public const TOOL_EXECUTION_FAILED = -32004;

    public const TENANT_NOT_FOUND = -32005;

    public const CLIENT_DISABLED = -32006;

    public const RATE_LIMITED = -32007;

    protected ?array $data = null;

    public function __construct(
        string $message = '',
        int $code = self::INTERNAL_ERROR,
        ?array $data = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function toJsonRpcError(?string $id = null): array
    {
        $error = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $this->getCode(),
                'message' => $this->getMessage(),
            ],
            'id' => $id,
        ];

        if ($this->data !== null) {
            $error['error']['data'] = $this->data;
        }

        return $error;
    }
}
