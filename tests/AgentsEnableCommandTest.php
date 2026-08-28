<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * agents:enable 批量启用命令测试
 *
 * 覆盖：--all 全量启用（小秘书除外）、幂等跳过、停用重启、
 * --role 指定启用、--sync-model 同步模板模型配置、
 * 下游扩展模板（id/key 字段风格）经注册表归一化后可被启用。
 */
class AgentsEnableCommandTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        BuiltinAgentTemplates::clearCache();
        AgentTemplateRegistry::flush();
        TenantContext::setTenantId('1001');
        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        AgentTemplateRegistry::flush();
        BuiltinAgentTemplates::clearCache();
        parent::tearDown();
    }

    public function test_requires_all_or_role_option(): void
    {
        $this->artisan('agents:enable', ['--tenant' => '1001'])->assertFailed();
    }

    public function test_enable_all_creates_every_template_except_secretary(): void
    {
        $this->artisan('agents:enable', ['--tenant' => '1001', '--all' => true])->assertSuccessful();

        $expected = count(AgentTemplateRegistry::enableable());

        $this->assertEquals($expected, Agent::where('tenant_id', 1001)->count());
        $this->assertEquals(0, Agent::where('role', 'system_secretary')->count());
    }

    public function test_enable_all_is_idempotent(): void
    {
        $this->artisan('agents:enable', ['--tenant' => '1001', '--all' => true])->assertSuccessful();
        $this->artisan('agents:enable', ['--tenant' => '1001', '--all' => true])->assertSuccessful();

        $this->assertEquals(count(AgentTemplateRegistry::enableable()), Agent::where('tenant_id', 1001)->count());
    }

    public function test_enable_re_enables_disabled_agent(): void
    {
        $this->artisan('agents:enable', ['--tenant' => '1001', '--all' => true])->assertSuccessful();

        $agent = Agent::query()->first();
        $agent->update(['enabled' => false]);

        $this->artisan('agents:enable', ['--tenant' => '1001', '--all' => true])->assertSuccessful();

        $this->assertTrue((bool) $agent->fresh()->enabled);
    }

    public function test_enable_specific_role_only(): void
    {
        $role = AgentTemplateRegistry::enableable()[0]['role'];

        $this->artisan('agents:enable', ['--tenant' => '1001', '--role' => [$role]])->assertSuccessful();

        $this->assertEquals(1, Agent::where('tenant_id', 1001)->count());
        $this->assertEquals($role, Agent::query()->first()->role);
    }

    public function test_enable_unknown_role_fails(): void
    {
        $this->artisan('agents:enable', ['--tenant' => '1001', '--role' => ['not_a_role']])->assertFailed();
    }

    public function test_sync_model_refreshes_existing_model_config(): void
    {
        config(['ai.secretary.extra_template_classes' => [FakeExtraTemplates::class]]);
        AgentTemplateRegistry::flush();

        $this->artisan('agents:enable', ['--tenant' => '1001', '--role' => ['scrm_demo']])->assertSuccessful();

        // 模拟线上旧数据：模型被写成套餐外过时值
        Agent::query()->where('role', 'scrm_demo')->update([
            'model_config' => json_encode(['model' => 'gpt-4o-mini']),
        ]);

        $this->artisan('agents:enable', [
            '--tenant' => '1001', '--role' => ['scrm_demo'], '--sync-model' => true,
        ])->assertSuccessful();

        $config = Agent::query()->where('role', 'scrm_demo')->first()->model_config;
        $this->assertEquals('qwen3.7-plus', $config['preferred_model']);
        $this->assertEquals('bailian', $config['preferred_provider']);
    }

    public function test_downstream_template_with_id_key_style_can_be_enabled(): void
    {
        config(['ai.secretary.extra_template_classes' => [FakeExtraTemplates::class]]);
        AgentTemplateRegistry::flush();

        $this->artisan('agents:enable', ['--tenant' => '1001', '--all' => true])->assertSuccessful();

        $agent = Agent::query()->where('role', 'scrm_demo')->first();

        $this->assertNotNull($agent);
        $this->assertTrue((bool) $agent->enabled);
        $this->assertTrue((bool) $agent->is_builtin);
        $this->assertEquals('演示员工', $agent->name);
    }
}

/**
 * 下游扩展模板桩类（模拟 ScrmAgentTemplates 的 id/key 字段风格）
 */
class FakeExtraTemplates
{
    public static function definitions(): array
    {
        return [
            [
                'id' => 901,
                'key' => 'scrm_demo',
                'name' => '演示员工',
                'description' => '测试用扩展模板',
                'avatar' => 'demo',
                'system_prompt' => '你是演示员工。',
                'tools' => [],
                'feature_keys' => [],
                'model_config' => [
                    'preferred_provider' => 'bailian',
                    'preferred_model' => 'qwen3.7-plus',
                    'temperature' => 0.7,
                    'max_tokens' => 2048,
                    'fallback_provider' => 'bailian',
                    'fallback_model' => 'qwen3.6-flash',
                ],
            ],
        ];
    }
}
