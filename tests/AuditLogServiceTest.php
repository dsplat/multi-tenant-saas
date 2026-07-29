<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AiAuditLog;
use MultiTenantSaas\Modules\Ai\Services\Agent\AuditLogService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AiModule;

class AuditLogServiceTest extends TestCase
{
    protected array $uses = [AiModule::class];

    protected AuditLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->service = new AuditLogService($this->app->make(TenantContextContract::class));
    }

    public function test_log_creates_audit_record(): void
    {
        $this->service->log(
            action: 'tool.execute',
            summary: '执行工具 navigate',
            agentId: 100,
            conversationId: 200,
            targetType: 'tool',
            targetId: 'navigate',
        );

        $log = AiAuditLog::first();
        $this->assertNotNull($log);
        $this->assertSame('tool.execute', $log->action);
        $this->assertSame(1001, $log->tenant_id);
        $this->assertSame(100, $log->agent_id);
        $this->assertSame(200, $log->conversation_id);
        $this->assertSame('success', $log->status);
    }

    public function test_log_tool_execution_convenience(): void
    {
        $this->service->logToolExecution(100, 200, 'navigate', ['path' => '/customers'], ['success' => true]);

        $log = AiAuditLog::first();
        $this->assertSame('tool.execute', $log->action);
        $this->assertSame('navigate', $log->target_id);
        $this->assertArrayHasKey('input', $log->detail);
    }

    public function test_log_delegation(): void
    {
        $this->service->logDelegation(100, 200, 300, '用户需要客服帮助');

        $log = AiAuditLog::first();
        $this->assertSame('agent.delegate', $log->action);
        $this->assertSame('200', $log->target_id);
        $this->assertSame(100, $log->agent_id);
        $this->assertStringContainsString('转派', $log->summary);
    }

    public function test_log_agent_toggle(): void
    {
        $this->service->logAgentToggle(100, '客服专员', true, 42);

        $log = AiAuditLog::first();
        $this->assertSame('agent.enable', $log->action);
        $this->assertSame(42, $log->operator_id);
        $this->assertStringContainsString('启用', $log->summary);
    }

    public function test_log_agent_disable(): void
    {
        $this->service->logAgentToggle(100, '销售顾问', false);

        $log = AiAuditLog::first();
        $this->assertSame('agent.disable', $log->action);
        $this->assertStringContainsString('停用', $log->summary);
    }

    public function test_query_filters_by_action(): void
    {
        $this->service->log(action: 'tool.execute', summary: 'a');
        $this->service->log(action: 'agent.enable', summary: 'b');
        $this->service->log(action: 'tool.execute', summary: 'c');

        $results = $this->service->query(['action' => 'tool.execute']);
        $this->assertSame(2, $results->total());
    }

    public function test_query_filters_by_agent(): void
    {
        $this->service->log(action: 'tool.execute', agentId: 1);
        $this->service->log(action: 'tool.execute', agentId: 2);

        $results = $this->service->query(['agent_id' => 1]);
        $this->assertSame(1, $results->total());
    }

    public function test_tenant_isolation(): void
    {
        Tenant::create(['tenant_id' => 2002, 'name' => 'B', 'slug' => 'b', 'status' => 'active']);

        $this->service->log(action: 'tool.execute', summary: 'tenant-a');

        TenantContext::setTenantId('2002');
        $this->service->log(action: 'tool.execute', summary: 'tenant-b');

        // 切回 A 查询
        TenantContext::setTenantId('1001');
        $results = $this->service->query();
        $this->assertSame(1, $results->total());
        $this->assertSame('tenant-a', $results->items()[0]->summary);
    }

    public function test_failed_status_recorded(): void
    {
        $this->service->logToolExecution(1, 2, 'bad_tool', [], ['error' => 'boom'], 'failed');

        $log = AiAuditLog::first();
        $this->assertSame('failed', $log->status);
    }
}
