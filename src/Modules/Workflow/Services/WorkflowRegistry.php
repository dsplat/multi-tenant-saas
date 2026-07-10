<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services;

use MultiTenantSaas\Contracts\WorkflowRegistryContract;
use MultiTenantSaas\Modules\Workflow\Models\Workflow;

class WorkflowRegistry implements WorkflowRegistryContract
{
    protected array $workflows = [];

    private function buildKey(string $name, int $tenantId): string
    {
        return "{$tenantId}:{$name}";
    }

    public function register(Workflow $workflow): void
    {
        $key = $this->buildKey($workflow->name, (int) $workflow->tenant_id);
        $this->workflows[$key] = $workflow;
    }

    public function getByName(string $name, int $tenantId): ?Workflow
    {
        $key = $this->buildKey($name, $tenantId);

        return $this->workflows[$key] ?? null;
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

    public function has(string $name, int $tenantId): bool
    {
        $key = $this->buildKey($name, $tenantId);

        return isset($this->workflows[$key]);
    }

    /**
     * @return string[]
     */
    public function names(?int $tenantId = null): array
    {
        if ($tenantId === null) {
            return array_values(array_map(
                fn (Workflow $workflow) => $workflow->name,
                $this->workflows
            ));
        }

        return array_values(array_map(
            fn (Workflow $workflow) => $workflow->name,
            array_filter(
                $this->workflows,
                fn (Workflow $workflow) => (int) $workflow->tenant_id === $tenantId
            )
        ));
    }

    public function unregister(string $name, int $tenantId): bool
    {
        $key = $this->buildKey($name, $tenantId);
        if (! isset($this->workflows[$key])) {
            return false;
        }
        unset($this->workflows[$key]);

        return true;
    }

    /**
     * @return array<string, mixed>[]
     */
    public function discover(?int $tenantId = null): array
    {
        $result = [];
        foreach ($this->workflows as $key => $workflow) {
            if ($tenantId !== null && (int) $workflow->tenant_id !== $tenantId) {
                continue;
            }
            $result[] = [
                'name' => $workflow->name,
                'workflow_id' => $workflow->workflow_id,
                'tenant_id' => $workflow->tenant_id,
                'type' => $workflow->type,
                'status' => $workflow->status,
            ];
        }

        return $result;
    }
}
