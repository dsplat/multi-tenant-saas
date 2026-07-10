<?php

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Modules\Workflow\Models\Workflow;

interface WorkflowRegistryContract
{
    public function register(Workflow $workflow): void;

    public function getByName(string $name, int $tenantId): ?Workflow;

    public function getByTenant(int $tenantId): array;

    public function all(): array;

    public function has(string $name, int $tenantId): bool;

    /**
     * @return string[]
     */
    public function names(?int $tenantId = null): array;

    public function unregister(string $name, int $tenantId): bool;

    /**
     * @return array<string, mixed>[]
     */
    public function discover(?int $tenantId = null): array;
}
