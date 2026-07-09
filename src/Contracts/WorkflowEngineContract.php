<?php

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Modules\Workflow\Models\Workflow;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowExecution;

interface WorkflowEngineContract
{
    public function execute(Workflow $workflow, array $context = []): WorkflowExecution;
    public function executeNode(array $node, array $context): array;
    public function getNextNode(array $nodes, array $currentNode): ?array;
    public function cancel(WorkflowExecution $execution): bool;
    public function retry(WorkflowExecution $execution, array $context = []): WorkflowExecution;
}
