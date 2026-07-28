<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Knowledge\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Knowledge\Services\ExternalKbService;

class KnowledgeTestConnectionHandler implements ToolHandlerContract
{
    public function __construct(private readonly ExternalKbService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->testConnection((int) $arguments['connection_id']);
    }
}
