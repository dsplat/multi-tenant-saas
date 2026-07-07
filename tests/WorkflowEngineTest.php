<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\Workflow;
use MultiTenantSaas\Models\WorkflowExecution;
use MultiTenantSaas\Models\WorkflowNode;
use MultiTenantSaas\Services\Workflow\WorkflowEngine;
use MultiTenantSaas\Services\Workflow\WorkflowRegistry;
use MultiTenantSaas\Services\Workflow\WorkflowService;
use MultiTenantSaas\Tests\Stubs\FakeToolRegistry;
use MultiTenantSaas\Tests\Schema\WorkflowModule;
use MultiTenantSaas\Tests\Schema\AgentModule;

class WorkflowEngineTest extends TestCase
{
    protected array $uses = [AgentModule::class, WorkflowModule::class];

    private Tenant $tenant;
    private FakeToolRegistry $toolRegistry;
    private TenantContextContract $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId((string) $this->tenant->tenant_id);
        $this->tenantContext = $this->app->make(TenantContextContract::class);
        $this->toolRegistry = new FakeToolRegistry();
    }

    private function createWorkflow(string $type = 'sequential'): Workflow
    {
        return Workflow::create([
            'tenant_id' => $this->tenant->tenant_id,
            'name' => 'Test Workflow',
            'type' => $type,
            'status' => 'active',
            'enabled' => true,
        ]);
    }

    private function createNodes(Workflow $workflow, array $nodeDefs = []): void
    {
        if (empty($nodeDefs)) {
            $nodeDefs = [
                ['name' => 'Start', 'type' => 'start', 'order' => 0],
                ['name' => 'Action', 'type' => 'action', 'order' => 1],
                ['name' => 'End', 'type' => 'end', 'order' => 2],
            ];
        }

        $prevNodeId = null;

        foreach ($nodeDefs as $index => $def) {
            $node = WorkflowNode::create([
                'workflow_id' => $workflow->workflow_id,
                'tenant_id' => $this->tenant->tenant_id,
                'name' => $def['name'],
                'type' => $def['type'],
                'config' => $def['config'] ?? null,
                'order' => $def['order'] ?? $index,
            ]);

            if ($prevNodeId !== null) {
                WorkflowNode::where('node_id', $prevNodeId)->update(['next_node_id' => $node->node_id]);
            }

            $prevNodeId = $node->node_id;
        }
    }

    public function test_execute_simple_workflow(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['input' => 'test']);

        $this->assertSame('completed', $execution->status);
        $this->assertNotNull($execution->started_at);
        $this->assertNotNull($execution->completed_at);
        $this->assertSame('test', $execution->context['input']);
    }

    public function test_execute_workflow_with_no_nodes_fails(): void
    {
        $workflow = $this->createWorkflow();

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow);

        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('no nodes', $execution->error);
    }

    public function test_execute_workflow_with_condition_node(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Check', 'type' => 'condition', 'order' => 1, 'config' => [
                'field' => 'value', 'operator' => 'gt', 'value' => 10,
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['value' => 20]);

        $this->assertSame('completed', $execution->status);
        $this->assertTrue($execution->context['_condition_result']);
    }

    public function test_execute_workflow_with_condition_false(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Check', 'type' => 'condition', 'order' => 1, 'config' => [
                'field' => 'value', 'operator' => 'gt', 'value' => 10,
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['value' => 5]);

        $this->assertSame('completed', $execution->status);
        $this->assertFalse($execution->context['_condition_result']);
    }

    public function test_execute_workflow_with_action_node(): void
    {
        $this->toolRegistry->register('test_tool', 'Test Tool', 'A test tool', 'TestHandler', []);

        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Do Action', 'type' => 'action', 'order' => 1, 'config' => [
                'tool' => 'test_tool', 'arguments' => ['param' => 'value'], 'output' => 'action_result',
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow);

        $this->assertSame('completed', $execution->status);
        $this->assertArrayHasKey('action_result', $execution->context);
    }

    public function test_execute_workflow_with_context_variable_resolution(): void
    {
        $this->toolRegistry->register('echo_tool', 'Echo Tool', 'An echo tool', 'EchoHandler', []);

        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Do Action', 'type' => 'action', 'order' => 1, 'config' => [
                'tool' => 'echo_tool', 'arguments' => ['input' => '$.my_var'], 'output' => 'echo_result',
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['my_var' => 'hello']);

        $this->assertSame('completed', $execution->status);
    }

    public function test_cancel_execution(): void
    {
        $workflow = $this->createWorkflow();
        $execution = WorkflowExecution::create([
            'tenant_id' => $this->tenant->tenant_id,
            'workflow_id' => $workflow->workflow_id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $this->assertTrue($engine->cancel($execution));
        $this->assertSame('cancelled', $execution->fresh()->status);
    }

    public function test_cancel_completed_execution_fails(): void
    {
        $workflow = $this->createWorkflow();
        $execution = WorkflowExecution::create([
            'tenant_id' => $this->tenant->tenant_id,
            'workflow_id' => $workflow->workflow_id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $this->assertFalse($engine->cancel($execution));
    }

    public function test_retry_failed_execution(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow);

        $execution = WorkflowExecution::create([
            'tenant_id' => $this->tenant->tenant_id,
            'workflow_id' => $workflow->workflow_id,
            'status' => 'failed',
            'error' => 'test error',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $newExecution = $engine->retry($execution);
        $this->assertSame('completed', $newExecution->status);
    }

    public function test_retry_non_failed_execution_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $workflow = $this->createWorkflow();
        $execution = WorkflowExecution::create([
            'tenant_id' => $this->tenant->tenant_id,
            'workflow_id' => $workflow->workflow_id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $engine->retry($execution);
    }

    public function test_get_next_node(): void
    {
        $nodes = [
            ['node_id' => 1, 'type' => 'start', 'next_node_id' => 2],
            ['node_id' => 2, 'type' => 'action', 'next_node_id' => 3],
            ['node_id' => 3, 'type' => 'end', 'next_node_id' => null],
        ];

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);

        $this->assertSame(2, $engine->getNextNode($nodes, $nodes[0])['node_id']);
        $this->assertSame(3, $engine->getNextNode($nodes, $nodes[1])['node_id']);
        $this->assertNull($engine->getNextNode($nodes, $nodes[2]));
    }

    public function test_service_create_workflow(): void
    {
        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $workflow = $service->create(['name' => 'Service WF', 'type' => 'sequential', 'status' => 'draft']);

        $this->assertNotNull($workflow->workflow_id);
        $this->assertSame('Service WF', $workflow->name);
    }

    public function test_service_find_workflow(): void
    {
        $workflow = $this->createWorkflow();

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $found = $service->find((string) $workflow->workflow_id);
        $this->assertNotNull($found);
        $this->assertSame($workflow->workflow_id, $found->workflow_id);
    }

    public function test_service_list_workflows(): void
    {
        $this->createWorkflow();
        $this->createWorkflow();

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $this->assertCount(2, $service->listForTenant());
    }

    public function test_service_list_with_status_filter(): void
    {
        $this->createWorkflow();
        Workflow::create([
            'tenant_id' => $this->tenant->tenant_id,
            'name' => 'Draft WF', 'type' => 'sequential', 'status' => 'draft',
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $this->assertCount(1, $service->listForTenant(['status' => 'active']));
        $this->assertCount(1, $service->listForTenant(['status' => 'draft']));
    }

    public function test_service_update_workflow(): void
    {
        $workflow = $this->createWorkflow();

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $updated = $service->update((string) $workflow->workflow_id, ['name' => 'Updated']);
        $this->assertSame('Updated', $updated->name);
    }

    public function test_service_delete_workflow(): void
    {
        $workflow = $this->createWorkflow();

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $this->assertTrue($service->delete((string) $workflow->workflow_id));
        $this->assertNull($service->find((string) $workflow->workflow_id));
    }

    public function test_service_update_status(): void
    {
        $workflow = $this->createWorkflow();

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $this->assertTrue($service->updateStatus((string) $workflow->workflow_id, 'archived'));
        $this->assertSame('archived', $workflow->fresh()->status);
    }

    public function test_service_start_execution(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $execution = $service->startExecution((string) $workflow->workflow_id, ['key' => 'val']);
        $this->assertSame('completed', $execution->status);
    }

    public function test_service_start_execution_inactive_workflow_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $workflow = $this->createWorkflow();
        $workflow->update(['status' => 'draft']);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $service->startExecution((string) $workflow->workflow_id);
    }

    public function test_service_start_execution_disabled_workflow_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $workflow = $this->createWorkflow();
        $workflow->update(['enabled' => false]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $service = new WorkflowService($this->tenantContext, $engine);

        $service->startExecution((string) $workflow->workflow_id);
    }

    public function test_registry_register_and_get(): void
    {
        $workflow = $this->createWorkflow();
        $registry = new WorkflowRegistry();

        $registry->register($workflow);

        $this->assertSame($workflow, $registry->getByName('Test Workflow'));
        $this->assertNull($registry->getByName('Nonexistent'));
    }

    public function test_registry_get_by_tenant(): void
    {
        $workflow = $this->createWorkflow();
        $registry = new WorkflowRegistry();

        $registry->register($workflow);

        $this->assertCount(1, $registry->getByTenant(1001));
        $this->assertEmpty($registry->getByTenant(9999));
    }

    public function test_registry_all(): void
    {
        $registry = new WorkflowRegistry();

        $registry->register($this->createWorkflow());
        $registry->register(Workflow::create([
            'tenant_id' => $this->tenant->tenant_id,
            'name' => 'Second WF', 'type' => 'sequential', 'status' => 'active',
        ]));

        $this->assertCount(2, $registry->all());
    }

    public function test_condition_operator_neq(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Check', 'type' => 'condition', 'order' => 1, 'config' => [
                'field' => 'val', 'operator' => 'neq', 'value' => 5,
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['val' => 10]);

        $this->assertTrue($execution->context['_condition_result']);
    }

    public function test_condition_operator_in(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Check', 'type' => 'condition', 'order' => 1, 'config' => [
                'field' => 'val', 'operator' => 'in', 'value' => [1, 2, 3],
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['val' => 2]);

        $this->assertTrue($execution->context['_condition_result']);
    }

    public function test_condition_operator_not_empty(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Check', 'type' => 'condition', 'order' => 1, 'config' => [
                'field' => 'val', 'operator' => 'not_empty',
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['val' => 'something']);

        $this->assertTrue($execution->context['_condition_result']);
    }

    public function test_condition_operator_not_empty_false(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0],
            ['name' => 'Check', 'type' => 'condition', 'order' => 1, 'config' => [
                'field' => 'val', 'operator' => 'not_empty',
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 2],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['val' => '']);

        $this->assertFalse($execution->context['_condition_result']);
    }

    public function test_start_node_initializes_default_variables(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0, 'config' => [
                'variables' => ['greeting' => 'hello', 'count' => 0],
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 1],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow);

        $this->assertSame('hello', $execution->context['greeting']);
        $this->assertSame(0, $execution->context['count']);
    }

    public function test_start_node_does_not_override_existing_context(): void
    {
        $workflow = $this->createWorkflow();
        $this->createNodes($workflow, [
            ['name' => 'Start', 'type' => 'start', 'order' => 0, 'config' => [
                'variables' => ['greeting' => 'default'],
            ]],
            ['name' => 'End', 'type' => 'end', 'order' => 1],
        ]);

        $engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $execution = $engine->execute($workflow, ['greeting' => 'custom']);

        $this->assertSame('custom', $execution->context['greeting']);
    }
}
