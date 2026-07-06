<?php

declare(strict_types=1);

namespace MultiTenantSaas\Mcp\Exceptions;

class ToolNotFoundException extends McpException
{
    public function __construct(string $toolSlug, mixed $data = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Tool not found: {$toolSlug}",
            self::TOOL_NOT_FOUND,
            $data ?? $toolSlug,
            $previous,
        );
    }
}
