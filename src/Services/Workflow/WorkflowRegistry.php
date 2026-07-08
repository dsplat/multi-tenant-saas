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

    public function has(string $name): bool
    {
        return isset($this->workflows[$name]);
    }

    public function names(): array
    {
        return array_keys($this->workflows);
    }

    public function unregister(string $name): void
    {
        unset($this->workflows[$name]);
    }

    public function discover(): array
    {
        $result = [];
        foreach ($this->workflows as $name => $workflow) {
            $result[] = [
                'name' => $name,
                'workflow_id' => $workflow->workflow_id,
                'tenant_id' => $workflow->tenant_id,
                'type' => $workflow->type,
                'status' => $workflow->status,
            ];
        }
        return $result;
    }
}
