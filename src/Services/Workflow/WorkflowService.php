<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Workflow;

use Illuminate\Support\Collection;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Contracts\WorkflowServiceContract;
use MultiTenantSaas\Models\Workflow;
use MultiTenantSaas\Models\WorkflowExecution;

class WorkflowService implements WorkflowServiceContract
{
    public function __construct(
        protected TenantContextContract $tenantContext,
        protected WorkflowEngineContract $engine,
    ) {}

    public function create(array $data): Workflow
    {
        $data['tenant_id'] = $this->tenantContext->getId();

        return Workflow::create($data);
    }

    public function update(string $workflowId, array $data): Workflow
    {
        $workflow = $this->find($workflowId);

        if ($workflow === null) {
            throw new \RuntimeException("Workflow {$workflowId} not found");
        }

        $workflow->update($data);

        return $workflow->fresh();
    }

    public function delete(string $workflowId): bool
    {
        $workflow = $this->find($workflowId);

        if ($workflow === null) {
            return false;
        }

        return (bool) $workflow->delete();
    }

    public function find(string $workflowId): ?Workflow
    {
        return Workflow::where('workflow_id', $workflowId)
            ->where('tenant_id', $this->tenantContext->getId())
            ->first();
    }

    public function listForTenant(array $filters = []): Collection
    {
        $query = Workflow::where('tenant_id', $this->tenantContext->getId());

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['enabled'])) {
            $query->where('enabled', $filters['enabled']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function startExecution(string $workflowId, array $context = []): WorkflowExecution
    {
        $workflow = $this->find($workflowId);

        if ($workflow === null) {
            throw new \RuntimeException("Workflow {$workflowId} not found");
        }

        if ($workflow->status !== 'active') {
            throw new \RuntimeException("Workflow {$workflowId} is not active");
        }

        if (!$workflow->enabled) {
            throw new \RuntimeException("Workflow {$workflowId} is disabled");
        }

        return $this->engine->execute($workflow, $context);
    }

    public function updateStatus(string $workflowId, string $status): bool
    {
        $workflow = $this->find($workflowId);

        if ($workflow === null) {
            return false;
        }

        return (bool) $workflow->update(['status' => $status]);
    }
}
