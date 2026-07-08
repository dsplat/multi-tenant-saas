<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Workflow;

use MultiTenantSaas\Models\Workflow;

class WorkflowRegistry
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
        return array_filter($this->workflows, fn($w) => $w->tenant_id == $tenantId);
    }

    public function has(string $name): bool
    {
        return isset($this->workflows[$name]);
    }

    public function unregister(string $name): bool
    {
        if (!isset($this->workflows[$name])) {
            return false;
        }
        unset($this->workflows[$name]);
        return true;
    }

    public function names(): array
    {
        return array_keys($this->workflows);
    }

    public function all(): array
    {
        return $this->workflows;
    }
}
