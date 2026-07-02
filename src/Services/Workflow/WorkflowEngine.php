<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Workflow;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Models\Workflow;
use MultiTenantSaas\Models\WorkflowExecution;
use MultiTenantSaas\Services\Workflow\Nodes\ActionNode;
use MultiTenantSaas\Services\Workflow\Nodes\ConditionNode;

class WorkflowEngine implements WorkflowEngineContract
{
    protected ActionNode $actionNode;
    protected ConditionNode $conditionNode;

    public function __construct(
        protected TenantContextContract $tenantContext,
        protected ToolRegistryContract $toolRegistry,
    ) {
        $this->actionNode = new ActionNode($toolRegistry);
        $this->conditionNode = new ConditionNode();
    }

    public function execute(Workflow $workflow, array $context = []): WorkflowExecution
    {
        $execution = WorkflowExecution::create([
            'tenant_id' => $this->tenantContext->getId(),
            'workflow_id' => $workflow->workflow_id,
            'status' => 'running',
            'context' => $context,
            'started_at' => now(),
        ]);

        try {
            $nodes = $workflow->nodes()->orderBy('order')->get()->toArray();

            if (empty($nodes)) {
                throw new \RuntimeException('Workflow has no nodes');
            }

            $currentNode = $this->findStartNode($nodes);

            if ($currentNode === null) {
                throw new \RuntimeException('Workflow has no start node');
            }

            while ($currentNode !== null) {
                $context = $this->executeNode($currentNode, $context);

                if (($currentNode['type'] ?? '') === 'end') {
                    break;
                }

                $currentNode = $this->getNextNode($nodes, $currentNode);
            }

            $execution->update([
                'status' => 'completed',
                'context' => $context,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('WorkflowEngine: execution failed', [
                'workflow_id' => $workflow->workflow_id,
                'execution_id' => $execution->execution_id,
                'error' => $e->getMessage(),
            ]);

            $execution->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }

        return $execution->fresh();
    }

    public function executeNode(array $node, array $context): array
    {
        $type = $node['type'] ?? 'unknown';

        return match ($type) {
            'start' => $this->executeStartNode($node, $context),
            'end' => $this->executeEndNode($node, $context),
            'action' => $this->actionNode->execute($node, $context, (int) $this->tenantContext->getId()),
            'condition' => $this->conditionNode->execute($node, $context),
            'wait' => $this->executeWaitNode($node, $context),
            default => $context,
        };
    }

    public function getNextNode(array $nodes, array $currentNode): ?array
    {
        $nextNodeId = $currentNode['next_node_id'] ?? null;

        if ($nextNodeId !== null) {
            foreach ($nodes as $node) {
                if (($node['node_id'] ?? '') == $nextNodeId) {
                    return $node;
                }
            }
        }

        $currentIndex = null;
        foreach ($nodes as $index => $node) {
            if (($node['node_id'] ?? '') == ($currentNode['node_id'] ?? '')) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex !== null && isset($nodes[$currentIndex + 1])) {
            return $nodes[$currentIndex + 1];
        }

        return null;
    }

    public function cancel(WorkflowExecution $execution): bool
    {
        if ($execution->status !== 'running' && $execution->status !== 'pending') {
            return false;
        }

        return (bool) $execution->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);
    }

    public function retry(WorkflowExecution $execution, array $context = []): WorkflowExecution
    {
        if ($execution->status !== 'failed') {
            throw new \RuntimeException('Only failed executions can be retried');
        }

        $workflow = $execution->workflow;

        if ($workflow === null) {
            throw new \RuntimeException('Workflow not found for execution');
        }

        return $this->execute($workflow, !empty($context) ? $context : ($execution->context ?? []));
    }

    public function getActionNode(): ActionNode
    {
        return $this->actionNode;
    }

    public function getConditionNode(): ConditionNode
    {
        return $this->conditionNode;
    }

    protected function findStartNode(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'start') {
                return $node;
            }
        }

        return null;
    }

    protected function executeStartNode(array $node, array $context): array
    {
        $config = $node['config'] ?? [];

        if (isset($config['variables'])) {
            foreach ($config['variables'] as $key => $default) {
                if (!array_key_exists($key, $context)) {
                    $context[$key] = $default;
                }
            }
        }

        return $context;
    }

    protected function executeEndNode(array $node, array $context): array
    {
        return $context;
    }

    protected function executeWaitNode(array $node, array $context): array
    {
        return $context;
    }
}
