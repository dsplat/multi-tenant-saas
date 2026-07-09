<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Workflow\Models\WorkflowExecution;
use MultiTenantSaas\Modules\Workflow\Services\RollbackService;
use MultiTenantSaas\Tests\Stubs\FakeToolRegistry;
use MultiTenantSaas\Tests\Schema\WorkflowModule;
use MultiTenantSaas\Tests\Schema\AgentModule;

class RollbackServiceTest extends TestCase
{
    protected array $uses = [AgentModule::class, WorkflowModule::class];

    private FakeToolRegistry $toolRegistry;
    private RollbackService $rollbackService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->toolRegistry = new FakeToolRegistry();
        $this->rollbackService = new RollbackService($this->toolRegistry);
    }

    public function test_can_rollback_failed_execution_with_executed_nodes(): void
    {
        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'failed',
            'context' => ['_executed_nodes' => ['node_1', 'node_2']],
        ]);

        $this->assertTrue($this->rollbackService->canRollback($execution));
    }

    public function test_cannot_rollback_completed_execution(): void
    {
        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'completed',
            'context' => ['_executed_nodes' => ['node_1']],
        ]);

        $this->assertFalse($this->rollbackService->canRollback($execution));
    }

    public function test_cannot_rollback_without_executed_nodes(): void
    {
        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'failed',
            'context' => [],
        ]);

        $this->assertFalse($this->rollbackService->canRollback($execution));
    }

    public function test_rollback_with_tool_handlers(): void
    {
        $this->toolRegistry->register('undo_tool', 'Undo Tool', 'An undo tool', 'UndoHandler', []);

        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'failed',
            'context' => [
                '_executed_nodes' => ['node_1'],
                '_rollback_handlers' => [
                    'node_1' => [
                        'type' => 'tool',
                        'tool' => 'undo_tool',
                        'arguments' => ['action' => 'undo'],
                    ],
                ],
                '_tenant_id' => 1001,
            ],
        ]);

        $result = $this->rollbackService->rollback($execution);

        $this->assertTrue($result['success']);
        $this->assertContains('node_1', $result['rolled_back_nodes']);
        $this->assertEmpty($result['errors']);
    }

    public function test_rollback_reverse_order(): void
    {
        $this->toolRegistry->register('undo_tool', 'Undo Tool', 'An undo tool', 'UndoHandler', []);

        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'failed',
            'context' => [
                '_executed_nodes' => ['node_1', 'node_2', 'node_3'],
                '_rollback_handlers' => [
                    'node_1' => ['type' => 'tool', 'tool' => 'undo_tool'],
                    'node_2' => ['type' => 'tool', 'tool' => 'undo_tool'],
                    'node_3' => ['type' => 'tool', 'tool' => 'undo_tool'],
                ],
                '_tenant_id' => 1001,
            ],
        ]);

        $result = $this->rollbackService->rollback($execution);

        $this->assertTrue($result['success']);
        $this->assertSame(['node_3', 'node_2', 'node_1'], $result['rolled_back_nodes']);
    }

    public function test_rollback_with_missing_handler_skips_node(): void
    {
        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'failed',
            'context' => [
                '_executed_nodes' => ['node_1', 'node_2'],
                '_rollback_handlers' => [
                    'node_1' => ['type' => 'tool', 'tool' => 'nonexistent'],
                ],
            ],
        ]);

        $result = $this->rollbackService->rollback($execution);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['rolled_back_nodes']);
    }

    public function test_rollback_cannot_rollback_returns_error(): void
    {
        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'completed',
            'context' => [],
        ]);

        $result = $this->rollbackService->rollback($execution);

        $this->assertFalse($result['success']);
        $this->assertContains('Execution cannot be rolled back', $result['errors']);
    }

    public function test_rollback_updates_execution_context(): void
    {
        $execution = WorkflowExecution::create([
            'tenant_id' => 1001,
            'workflow_id' => 9999,
            'status' => 'failed',
            'context' => [
                '_executed_nodes' => ['node_1'],
                '_rollback_handlers' => [
                    'node_1' => ['type' => 'tool', 'tool' => 'undo_tool'],
                ],
            ],
        ]);

        $this->rollbackService->rollback($execution);

        $execution->refresh();
        $this->assertTrue($execution->context['_rolled_back']);
        $this->assertContains('node_1', $execution->context['_rolled_back_nodes']);
    }

    public function test_register_rollback_handler(): void
    {
        $context = [];
        $handler = ['type' => 'tool', 'tool' => 'undo_tool'];

        $result = $this->rollbackService->registerRollbackHandler($context, 'node_1', $handler);

        $this->assertSame($handler, $result['_rollback_handlers']['node_1']);
    }

    public function test_track_executed_node(): void
    {
        $context = [];

        $result = $this->rollbackService->trackExecutedNode($context, 'node_1');

        $this->assertContains('node_1', $result['_executed_nodes']);
    }

    public function test_track_executed_node_no_duplicates(): void
    {
        $context = ['_executed_nodes' => ['node_1']];

        $result = $this->rollbackService->trackExecutedNode($context, 'node_1');

        $this->assertCount(1, $result['_executed_nodes']);
    }

    public function test_execute_rollback_handler_tool(): void
    {
        $this->toolRegistry->register('undo_tool', 'Undo Tool', 'An undo tool', 'UndoHandler', []);

        $handler = ['type' => 'tool', 'tool' => 'undo_tool', 'arguments' => ['action' => 'undo']];
        $context = ['_tenant_id' => 1001];

        $result = $this->rollbackService->executeRollbackHandler($handler, $context);

        $this->assertSame('undo_tool', $result['tool']);
    }

    public function test_execute_rollback_handler_callback(): void
    {
        $handler = [
            'type' => 'callback',
            'callback' => function (array $context) {
                return 'callback_result';
            },
        ];

        $result = $this->rollbackService->executeRollbackHandler($handler, []);

        $this->assertSame('callback_result', $result);
    }
}
