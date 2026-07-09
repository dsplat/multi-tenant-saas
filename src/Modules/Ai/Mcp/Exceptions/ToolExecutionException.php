<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp\Exceptions;

class ToolExecutionException extends McpException
{
    public function __construct(string $toolSlug, string $errorMessage, mixed $data = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Tool execution failed: {$toolSlug} - {$errorMessage}",
            self::TOOL_EXECUTION_FAILED,
            $data ?? ['tool' => $toolSlug, 'error' => $errorMessage],
            $previous,
        );
    }
}
