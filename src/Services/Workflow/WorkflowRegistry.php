<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Workflow;

use MultiTenantSaas\Contracts\WorkflowRegistryContract;
use MultiTenantSaas\Models\Workflow;

class WorkflowRegistry implements WorkflowRegistryContract
{
    protected array $workflows = [];

    public function register(Workflow $workflow): void
    {
        $this->workflows[$workflow->name] = $workflow;
    }

    public function getByName(string $name): ?Workflow
    {
        return $this->workflows[$name] ?? null;
    }

    public function getByTenant(int $tenantId): array
    {
        return array_values(array_filter(
            $this->workflows,
            fn (Workflow $workflow) => (int) $workflow->tenant_id === $tenantId
        ));
    }

    public function all(): array
    {
        return array_values($this->workflows);
    }
}