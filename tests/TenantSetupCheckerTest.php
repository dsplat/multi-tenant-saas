<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Assistant\TenantSetupChecker;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Knowledge\Models\ExternalKbConnection;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\KnowledgeModule;

/**
 * 租户设置完善度检查器测试
 *
 * 覆盖：四个内置检查项（微信登录/员工邀请/知识库/数字员工）的
 * 完成判定、completed/total 统计、下游扩展检查类接入。
 */
class TenantSetupCheckerTest extends TestCase
{
    protected array $uses = [AgentModule::class, KnowledgeModule::class];

    private TenantSetupChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId('1001');
        $this->checker = new TenantSetupChecker;
    }

    public function test_fresh_tenant_has_all_items_undone(): void
    {
        $checklist = $this->checker->checklist(1001);

        $this->assertEquals(4, $checklist['total']);
        $this->assertEquals(0, $checklist['completed']);

        foreach ($checklist['items'] as $item) {
            $this->assertFalse($item['done'], "{$item['key']} 应为未完成");
            $this->assertNotEmpty($item['route'], "{$item['key']} 需携带跳转路由");
            $this->assertNotEmpty($item['prompt'], "{$item['key']} 需携带唤醒小助手提示词");
        }
    }

    public function test_wechat_login_done_when_client_id_configured(): void
    {
        TenantSetting::set(1001, 'oauth', 'wechat_client_id', 'wx123456');

        $this->assertTrue($this->itemDone('wechat_login'));
    }

    public function test_wechat_login_done_when_wechat_work_configured(): void
    {
        TenantSetting::set(1001, 'oauth', 'wechat_work_corp_id', 'ww123456');

        $this->assertTrue($this->itemDone('wechat_login'));
    }

    public function test_staff_invited_requires_more_than_one_active_member(): void
    {
        OperatorTenant::create(['operator_id' => 1, 'tenant_id' => 1001, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->assertFalse($this->itemDone('staff_invited'), '只有创建者时不算已邀请');

        OperatorTenant::create(['operator_id' => 2, 'tenant_id' => 1001, 'role' => 'staff', 'is_active' => true]);

        $this->assertTrue($this->itemDone('staff_invited'));
    }

    public function test_knowledge_base_done_only_with_active_connection(): void
    {
        ExternalKbConnection::forceCreate([
            'connection_id' => 9001,
            'tenant_id' => 1001,
            'provider_type' => 'ragflow',
            'name' => '业务知识库',
            'api_url' => 'https://kb.example.com',
            'status' => ExternalKbConnection::STATUS_DISABLED,
        ]);

        $this->assertFalse($this->itemDone('knowledge_base'), '停用连接不算完成');

        ExternalKbConnection::forceCreate([
            'connection_id' => 9002,
            'tenant_id' => 1001,
            'provider_type' => 'ragflow',
            'name' => '业务知识库 2',
            'api_url' => 'https://kb2.example.com',
            'status' => ExternalKbConnection::STATUS_ACTIVE,
        ]);

        $this->assertTrue($this->itemDone('knowledge_base'));
    }

    public function test_agents_enabled_ignores_secretary(): void
    {
        Agent::forceCreate([
            'agent_id' => 1001, 'tenant_id' => 1001, 'name' => '系统小秘书',
            'role' => 'system_secretary', 'system_prompt' => 'x', 'model_config' => [], 'enabled' => true,
        ]);

        $this->assertFalse($this->itemDone('agents_enabled'), '仅小秘书不算已启用数字员工');

        Agent::forceCreate([
            'agent_id' => 1002, 'tenant_id' => 1001, 'name' => '销售助手',
            'role' => 'sales_assistant', 'system_prompt' => 'x', 'model_config' => [], 'enabled' => true,
        ]);

        $this->assertTrue($this->itemDone('agents_enabled'));
    }

    public function test_extra_setup_checkers_are_appended(): void
    {
        config(['ai.secretary.extra_setup_checkers' => [FakeSetupChecks::class]]);

        $checklist = $this->checker->checklist(1001);

        $this->assertEquals(5, $checklist['total']);
        $this->assertEquals(1, $checklist['completed']);

        $keys = array_column($checklist['items'], 'key');
        $this->assertContains('scrm_channel', $keys);

        // 扩展项 prompt 透传（Dashboard 引导卡片据此唤起小秘书）
        $scrmChannel = collect($checklist['items'])->firstWhere('key', 'scrm_channel');
        $this->assertSame('帮我配置获客渠道', $scrmChannel['prompt']);
    }

    private function itemDone(string $key): bool
    {
        foreach ($this->checker->checklist(1001)['items'] as $item) {
            if ($item['key'] === $key) {
                return $item['done'];
            }
        }

        $this->fail("检查项 {$key} 不存在");
    }
}

/**
 * 下游扩展检查类桩（模拟 SCRM 渠道配置检查）
 */
class FakeSetupChecks
{
    public function checks(int $tenantId): array
    {
        return [
            [
                'key' => 'scrm_channel',
                'label' => '配置获客渠道',
                'done' => true,
                'route' => '/channels',
                'description' => '配置至少一个获客渠道。',
                'prompt' => '帮我配置获客渠道',
            ],
        ];
    }
}
