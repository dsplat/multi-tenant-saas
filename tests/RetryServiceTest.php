<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Workflow\Models\Workflow;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowExecution;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowNode;
use MultiTenantSaas\Modules\Workflow\Services\RetryService;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowEngine;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\WorkflowModule;
use MultiTenantSaas\Tests\Stubs\FakeToolRegistry;

class RetryServiceTest extends TestCase
{
    protected array $uses = [AgentModule::class, WorkflowModule::class];

    private Tenant $tenant;

    private FakeToolRegistry $toolRegistry;

    private TenantContextContract $tenantContext;

    private WorkflowEngine $engine;

    private RetryService $retryService;

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
        $this->toolRegistry = new FakeToolRegistry;
        $this->engine = new WorkflowEngine($this->tenantContext, $this->toolRegistry);
        $this->retryService = new RetryService($this->engine, 3, 60, 'exponential');
    }

    private function createFailedExecution(array $context = []): WorkflowExecution
    {
        $workflow = Workflow::create([
            'tenant_id' => $this->tenant->tenant_id,
            'name' => 'Test Workflow',
            'type' => 'sequential',
            'status' => 'active',
            'enabled' => true,
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenant->tenant_id,
            'name' => 'Start',
            'type' => 'start',
            'order' => 0,
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenant->tenant_id,
            'name' => 'End',
            'type' => 'end',
            'order' => 1,
        ]);

        return WorkflowExecution::create([
            'tenant_id' => $this->tenant->tenant_id,
            'workflow_id' => $workflow->workflow_id,
            'status' => 'failed',
            'error' => 'Test error',
            'context' => $context,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function test_can_retry_failed_execution(): void
    {
        $execution = $this->createFailedExecution();

        $this->assertTrue($this->retryService->canRetry($execution));
    }

    public function test_cannot_retry_completed_execution(): void
    {
        $execution = $this->createFailedExecution();
        $execution->update(['status' => 'completed']);

        $this->assertFalse($this->retryService->canRetry($execution));
    }

    public function test_cannot_retry_when_max_retries_reached(): void
    {
        $execution = $this->createFailedExecution(['_retry_count' => 3]);

        $this->assertFalse($this->retryService->canRetry($execution));
    }

    public function test_get_retry_count(): void
    {
        $execution = $this->createFailedExecution(['_retry_count' => 2]);

        $this->assertSame(2, $this->retryService->getRetryCount($execution));
    }

    public function test_get_retry_count_default_zero(): void
    {
        $execution = $this->createFailedExecution();

        $this->assertSame(0, $this->retryService->getRetryCount($execution));
    }

    public function test_get_next_retry_delay_exponential(): void
    {
        $execution0 = $this->createFailedExecution(['_retry_count' => 0]);
        $execution1 = $this->createFailedExecution(['_retry_count' => 1]);
        $execution2 = $this->createFailedExecution(['_retry_count' => 2]);

        $this->assertSame(60, $this->retryService->getNextRetryDelay($execution0));
        $this->assertSame(120, $this->retryService->getNextRetryDelay($execution1));
        $this->assertSame(240, $this->retryService->getNextRetryDelay($execution2));
    }

    public function test_get_next_retry_delay_linear(): void
    {
        $retryService = new RetryService($this->engine, 3, 60, 'linear');

        $execution0 = $this->createFailedExecution(['_retry_count' => 0]);
        $execution1 = $this->createFailedExecution(['_retry_count' => 1]);

        $this->assertSame(60, $retryService->getNextRetryDelay($execution0));
        $this->assertSame(120, $retryService->getNextRetryDelay($execution1));
    }

    public function test_get_next_retry_delay_fixed(): void
    {
        $retryService = new RetryService($this->engine, 3, 60, 'fixed');

        $execution0 = $this->createFailedExecution(['_retry_count' => 0]);
        $execution1 = $this->createFailedExecution(['_retry_count' => 2]);

        $this->assertSame(60, $retryService->getNextRetryDelay($execution0));
        $this->assertSame(60, $retryService->getNextRetryDelay($execution1));
    }

    public function test_retry_increments_count(): void
    {
        $execution = $this->createFailedExecution();

        $newExecution = $this->retryService->retry($execution);

        $this->assertSame(1, $newExecution->context['_retry_count']);
    }

    public function test_retry_preserves_last_error(): void
    {
        $execution = $this->createFailedExecution();

        $newExecution = $this->retryService->retry($execution);

        $this->assertSame('Test error', $newExecution->context['_last_error']);
    }

    public function test_retry_with_override_context(): void
    {
        $execution = $this->createFailedExecution();

        $newExecution = $this->retryService->retry($execution, ['new_key' => 'new_value']);

        $this->assertSame('new_value', $newExecution->context['new_key']);
        $this->assertSame(1, $newExecution->context['_retry_count']);
    }

    public function test_retry_throws_when_cannot_retry(): void
    {
        $this->expectException(\RuntimeException::class);

        $execution = $this->createFailedExecution(['_retry_count' => 3]);

        $this->retryService->retry($execution);
    }

    public function test_retry_with_delay_returns_info(): void
    {
        $execution = $this->createFailedExecution();

        $result = $this->retryService->retryWithDelay($execution);

        $this->assertSame($execution, $result['execution']);
        $this->assertSame(60, $result['delay_seconds']);
        $this->assertTrue($result['can_retry']);
        $this->assertSame(0, $result['retry_count']);
        $this->assertSame(3, $result['max_retries']);
        $this->assertArrayHasKey('retry_at', $result);
    }

    public function test_get_max_retries(): void
    {
        $this->assertSame(3, $this->retryService->getMaxRetries());
    }

    public function test_get_base_delay(): void
    {
        $this->assertSame(60, $this->retryService->getBaseDelay());
    }

    public function test_get_backoff_strategy(): void
    {
        $this->assertSame('exponential', $this->retryService->getBackoffStrategy());
    }
}
