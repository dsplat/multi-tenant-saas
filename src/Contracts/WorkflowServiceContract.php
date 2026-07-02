<?php

namespace MultiTenantSaas\Contracts;

use Illuminate\Support\Collection;
use MultiTenantSaas\Models\Workflow;

interface WorkflowServiceContract
{
    public function create(array $data): Workflow;
    public function update(string $workflowId, array $data): Workflow;
    public function delete(string $workflowId): bool;
    public function find(string $workflowId): ?Workflow;
    public function listForTenant(array $filters = []): Collection;
    public function startExecution(string $workflowId, array $context = []): \MultiTenantSaas\Models\WorkflowExecution;
    public function updateStatus(string $workflowId, string $status): bool;
}
