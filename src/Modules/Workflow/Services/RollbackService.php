<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowExecution;

class RollbackService
{
    public function __construct(
        protected ToolRegistryContract $toolRegistry,
    ) {}

    public function canRollback(WorkflowExecution $execution): bool
    {
        if ($execution->status !== 'failed') {
            return false;
        }

        $executedNodes = $execution->context['_executed_nodes'] ?? [];

        return !empty($executedNodes);
    }

    /**
     * @return array{success: bool, rolled_back_nodes: list<string>, errors: list<string>}
     */
    public function rollback(WorkflowExecution $execution): array
    {
        if (!$this->canRollback($execution)) {
            return [
                'success' => false,
                'rolled_back_nodes' => [],
                'errors' => ['Execution cannot be rolled back'],
            ];
        }

        $executedNodes = $execution->context['_executed_nodes'] ?? [];
        $rollbackHandlers = $execution->context['_rollback_handlers'] ?? [];
        $rolledBack = [];
        $errors = [];

        $reversedNodes = array_reverse($executedNodes);

        foreach ($reversedNodes as $nodeId) {
            $handler = $rollbackHandlers[$nodeId] ?? null;

            if ($handler === null) {
                Log::warning('RollbackService: no rollback handler for node', [
                    'node_id' => $nodeId,
                ]);
                continue;
            }

            try {
                $result = $this->executeRollbackHandler($handler, $execution->context);
                $rolledBack[] = $nodeId;

                Log::info('RollbackService: rolled back node', [
                    'node_id' => $nodeId,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $errors[] = "Failed to rollback node {$nodeId}: {$e->getMessage()}";

                Log::error('RollbackService: rollback failed for node', [
                    'node_id' => $nodeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $success = empty($errors);

        $execution->update([
            'context' => array_merge($execution->context ?? [], [
                '_rolled_back' => $success,
                '_rolled_back_nodes' => $rolledBack,
                '_rollback_errors' => $errors,
            ]),
        ]);

        return [
            'success' => $success,
            'rolled_back_nodes' => $rolledBack,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $handler
     * @param  array<string, mixed>  $context
     * @return mixed
     */
    public function executeRollbackHandler(array $handler, array $context): mixed
    {
        $type = $handler['type'] ?? 'tool';

        return match ($type) {
            'tool' => $this->executeToolRollback($handler, $context),
            'callback' => $this->executeCallbackRollback($handler, $context),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $handler
     * @param  array<string, mixed>  $context
     * @return mixed
     */
    protected function executeToolRollback(array $handler, array $context): mixed
    {
        $toolSlug = $handler['tool'] ?? '';

        if ($toolSlug === '') {
            return null;
        }

        $arguments = $handler['arguments'] ?? [];
        $tenantId = $context['_tenant_id'] ?? 0;

        return $this->toolRegistry->execute($toolSlug, $arguments, $tenantId);
    }

    /**
     * @param  array<string, mixed>  $handler
     * @param  array<string, mixed>  $context
     * @return mixed
     */
    protected function executeCallbackRollback(array $handler, array $context): mixed
    {
        $callback = $handler['callback'] ?? null;

        if ($callback === null || !is_callable($callback)) {
            return null;
        }

        return $callback($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  string  $nodeId
     * @param  array<string, mixed>  $rollbackHandler
     * @return array<string, mixed>
     */
    public function registerRollbackHandler(array $context, string $nodeId, array $rollbackHandler): array
    {
        if (!isset($context['_rollback_handlers'])) {
            $context['_rollback_handlers'] = [];
        }

        $context['_rollback_handlers'][$nodeId] = $rollbackHandler;

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  string  $nodeId
     * @return array<string, mixed>
     */
    public function trackExecutedNode(array $context, string $nodeId): array
    {
        if (!isset($context['_executed_nodes'])) {
            $context['_executed_nodes'] = [];
        }

        if (!in_array($nodeId, $context['_executed_nodes'], true)) {
            $context['_executed_nodes'][] = $nodeId;
        }

        return $context;
    }
}
