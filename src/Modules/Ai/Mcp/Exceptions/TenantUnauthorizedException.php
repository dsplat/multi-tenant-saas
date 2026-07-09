<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp\Exceptions;

class TenantUnauthorizedException extends McpException
{
    public function __construct(string $tenantId, mixed $data = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Tenant unauthorized: {$tenantId}",
            self::TENANT_UNAUTHORIZED,
            $data ?? $tenantId,
            $previous,
        );
    }
}
