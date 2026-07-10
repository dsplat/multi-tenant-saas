<?php

namespace MultiTenantSaas\Contracts;

use Illuminate\Support\Collection;
use MultiTenantSaas\Modules\Workflow\Models\Workflow;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowExecution;

interface WorkflowServiceContract
{
    public function create(array $data): Workflow;

    public function update(string $workflowId, array $data): Workflow;

    public function delete(string $workflowId): bool;

    public function find(string $workflowId): ?Workflow;

    public function listForTenant(array $filters = []): Collection;

    public function startExecution(string $workflowId, array $context = []): WorkflowExecution;

    public function updateStatus(string $workflowId, string $status): bool;
}
