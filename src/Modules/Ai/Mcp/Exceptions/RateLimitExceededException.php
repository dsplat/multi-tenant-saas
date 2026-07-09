<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp\Exceptions;

class RateLimitExceededException extends McpException
{
    public function __construct(string $clientId, int $limit, mixed $data = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Rate limit exceeded for client: {$clientId} (limit: {$limit})",
            self::RATE_LIMITED,
            $data ?? ['client_id' => $clientId, 'limit' => $limit],
            $previous,
        );
    }
}
