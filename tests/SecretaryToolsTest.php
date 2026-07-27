<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Tool\DelegateToAgentTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\ListAgentsTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\NavigateTool;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * 系统小秘书工具集单元测试
 *
 * 覆盖：NavigateTool 路径校验、ListAgentsTool 秘书自我排除与租户隔离、
 * DelegateToAgentTool 目标校验与转派指令结构
 */
class SecretaryToolsTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId('1001');
    }

    private function createAgent(array $overrides = []): Agent
    {
        return Agent::create(array_merge([
            'tenant_id' => 1001,
            'name' => '客服专员',
            'role' => 'customer_service',
            'system_prompt' => '你是客服。',
            'model_config' => [],
            'enabled' => true,
        ], $overrides));
    }

    // ---------- NavigateTool ----------

    public function test_navigate_returns_navigate_action(): void
    {
        $result = (new NavigateTool)(['route_path' => '/console/coupons', 'label' => '优惠券'], 1001);

        $this->assertEquals('navigate', $result['action']);
        $this->assertEquals('/console/coupons', $result['route_path']);
        $this->assertEquals('优惠券', $result['label']);
    }

    public function test_navigate_label_defaults_to_path(): void
    {
        $result = (new NavigateTool)(['route_path' => '/console/users'], 1001);

        $this->assertEquals('/console/users', $result['label']);
    }

    public function test_navigate_rejects_empty_path(): void
    {
        $result = (new NavigateTool)([], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_navigate_rejects_external_url(): void
    {
        $result = (new NavigateTool)(['route_path' => 'https://evil.com'], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_navigate_rejects_protocol_relative_url(): void
    {
        $result = (new NavigateTool)(['route_path' => '//evil.com'], 1001);

        $this->assertTrue($result['error']);
    }

    // ---------- ListAgentsTool ----------

    public function test_list_agents_returns_enabled_agents(): void
    {
        $this->createAgent(['name' => '客服', 'role' => 'customer_service']);
        $this->createAgent(['name' => '销售', 'role' => 'sales']);

        $result = (new ListAgentsTool)([], 1001);

        $this->assertEquals(2, $result['total']);
        // agent_id 为全局随机 ID，顺序不固定
        $this->assertEqualsCanonicalizing(['客服', '销售'], array_column($result['agents'], 'name'));
    }

    public function test_list_agents_excludes_secretary_itself(): void
    {
        $this->createAgent(['name' => '小秘书', 'role' => 'system_secretary']);
        $this->createAgent(['name' => '客服', 'role' => 'customer_service']);

        $result = (new ListAgentsTool)([], 1001);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('客服', $result['agents'][0]['name']);
    }

    public function test_list_agents_excludes_disabled_agents(): void
    {
        $this->createAgent(['name' => '停用员工', 'enabled' => false]);

        $result = (new ListAgentsTool)([], 1001);

        $this->assertEquals(0, $result['total']);
    }

    public function test_list_agents_isolated_by_tenant(): void
    {
        $this->createAgent(['tenant_id' => 9999, 'name' => '别家员工']);

        $result = (new ListAgentsTool)([], 1001);

        $this->assertEquals(0, $result['total']);
    }

    public function test_list_agents_returns_string_agent_id(): void
    {
        $agent = $this->createAgent();

        $result = (new ListAgentsTool)([], 1001);

        $this->assertSame((string) $agent->agent_id, $result['agents'][0]['agent_id']);
    }

    // ---------- DelegateToAgentTool ----------

    public function test_delegate_returns_delegate_action(): void
    {
        $agent = $this->createAgent(['name' => '营销专员', 'role' => 'marketing']);

        $result = (new DelegateToAgentTool)([
            'agent_id' => (string) $agent->agent_id,
            'reason' => '需要写文案',
            'handoff_message' => '请帮用户写一篇活动文案',
        ], 1001);

        $this->assertEquals('delegate', $result['action']);
        $this->assertEquals((string) $agent->agent_id, $result['agent_id']);
        $this->assertEquals('营销专员', $result['agent_name']);
        $this->assertEquals('marketing', $result['agent_role']);
        $this->assertEquals('需要写文案', $result['reason']);
        $this->assertEquals('请帮用户写一篇活动文案', $result['handoff_message']);
    }

    public function test_delegate_rejects_missing_agent_id(): void
    {
        $result = (new DelegateToAgentTool)([], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_delegate_rejects_nonexistent_agent(): void
    {
        $result = (new DelegateToAgentTool)(['agent_id' => '999999'], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_delegate_rejects_disabled_agent(): void
    {
        $agent = $this->createAgent(['enabled' => false]);

        $result = (new DelegateToAgentTool)(['agent_id' => (string) $agent->agent_id], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_delegate_rejects_other_tenant_agent(): void
    {
        $agent = $this->createAgent(['tenant_id' => 9999]);

        $result = (new DelegateToAgentTool)(['agent_id' => (string) $agent->agent_id], 1001);

        $this->assertTrue($result['error']);
    }
}
