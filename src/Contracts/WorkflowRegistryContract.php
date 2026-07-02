<?php

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Models\Workflow;

interface WorkflowRegistryContract
{
    public function register(Workflow $workflow): void;
    public function getByName(string $name): ?Workflow;
    public function getByTenant(int $tenantId): array;
    public function all(): array;
}
