<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Storage\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Storage\Services\FileService;

class StorageGetUsageHandler implements ToolHandlerContract
{
    public function __construct(private readonly FileService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getStorageUsage($tenantId);
    }
}
