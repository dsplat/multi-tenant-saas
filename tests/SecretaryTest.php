<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentChatClient;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentService;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * 系统小秘书（第 0 号数字员工）单元测试
 *
 * 覆盖：模板 0 定义、模板克隆、AgentRuntime 平台级模型配置旁路、
 * secretary:install 命令幂等安装
 */
class SecretaryTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        BuiltinAgentTemplates::clearCache();
        AgentTemplateRegistry::flush();
        TenantContext::setTenantId('1001');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        BuiltinAgentTemplates::clearCache();
        AgentTemplateRegistry::flush();
        parent::tearDown();
    }

    // ---------- 小秘书模板（seq=0 由 Registry 派生，template_id 不硬编码） ----------

    public function test_secretary_template_exists_with_seq_zero(): void
    {
        // seq 由 AgentTemplateRegistry 按定义顺序派生（唯一事实源）
        $template = AgentTemplateRegistry::findByKey('system_secretary');

        $this->assertNotNull($template);
        $this->assertEquals(0, $template['seq']);
        $this->assertNotEquals(0, $template['template_id'], 'template_id 禁用 0（falsy）');
        $this->assertEquals('system_secretary', $template['role']);
        $this->assertEquals('系统小秘书', $template['name']);
    }

    public function test_secretary_template_is_first_in_definitions(): void
    {
        $definitions = AgentTemplateRegistry::definitions();

        $this->assertEquals(0, $definitions[0]['seq']);
        $this->assertEquals('system_secretary', $definitions[0]['template_key']);
    }

    public function test_secretary_template_has_expected_tools(): void
    {
        $template = BuiltinAgentTemplates::findByKey('system_secretary');

        // tools：框架注册工具（必须已注册，缺失即失败，由 AgentTemplateToolsConsistencyTest 守门）
        $this->assertEquals(
            [
                'system_kb_search', 'get_data_dictionary', 'navigate', 'suggest_form_fill', 'ask_user_choice', 'suggest_kb_update', 'list_agents', 'delegate_to_agent', 'enable_agent', 'fetch_site_metadata', 'update_tenant_branding', 'update_tenant_settings', 'update_tenant_domain',
                'list_task_chains', 'start_task_chain', 'advance_task_chain',
                'activity_plan_draft', 'activity_plan_commit', 'activity_status',
                'thread_review', 'thread_track', 'thread_untrack',
                'create_product', 'product_list', 'coupon_list', 'sms_list_templates',
                'course_list', 'create_course', 'update_course',
            ],
            $template['tools']
        );

        // optional_tools：下游 L2 代操作工具 + 前置查询配套（未注册静默跳过属设计意图）
        $this->assertEquals(
            [
                'tag_customer', 'create_script_draft', 'save_oauth_config', 'create_distribution_plan',
                'manage_tags', 'ai_auto_tag', 'create_live_code', 'send_message', 'issue_coupon',
                'create_sms_signature', 'send_sms_batch', 'schedule_sms_batch', 'create_poster',
                'adjust_points', 'create_moments_sop', 'create_mass_push',
                'set_group_announcement', 'trigger_chat_archive_sync',
                'get_community_list',
                'search_customer', 'get_customer_tags', 'list_coupon_templates', 'list_poster_templates',
                'get_points_balance', 'list_sms_signatures', 'list_moments_sop', 'list_mass_push',
                'list_external_contacts', 'list_group_bot_rules', 'list_welcome_messages',
                'list_chat_archives', 'search_chat_archive',
            ],
            $template['optional_tools']
        );
    }

    public function test_secretary_model_config_reads_from_config(): void
    {
        config([
            'ai.secretary.provider' => 'bailian',
            'ai.secretary.model' => 'qwen-flash',
            'ai.secretary.fallback_model' => 'deepseek-v3',
        ]);

        $config = BuiltinAgentTemplates::secretaryModelConfig();

        $this->assertEquals('bailian', $config['preferred_provider']);
        $this->assertEquals('qwen-flash', $config['preferred_model']);
        $this->assertEquals('deepseek-v3', $config['fallback_model']);
    }

    public function test_clone_secretary_template_creates_secretary_agent(): void
    {
        $templateId = (int) BuiltinAgentTemplates::findByKey('system_secretary')['template_id'];
        $agent = app(AgentService::class)->cloneFromTemplate($templateId, 1001);

        $this->assertEquals('system_secretary', $agent->role);
        $this->assertEquals(1001, $agent->tenant_id);
        $this->assertTrue($agent->is_builtin);
        $this->assertContains('system_kb_search', $agent->tools);
    }

    // ---------- AgentRuntime 模型配置旁路 ----------

    private function resolveModelConfig(Agent $agent): array
    {
        // resolveModelConfig 已迁至 AgentChatClient（与 AgentRuntime 非流式链路共用口径）
        $client = new AgentChatClient(
            Mockery::mock(AiTextServiceContract::class),
            Mockery::mock(ToolRegistryContract::class),
        );

        return $client->resolveModelConfig($agent);
    }

    public function test_secretary_agent_forces_platform_model_config(): void
    {
        config([
            'ai.secretary.enabled' => true,
            'ai.secretary.provider' => 'bailian',
            'ai.secretary.model' => 'qwen-flash',
        ]);

        $agent = new Agent([
            'role' => 'system_secretary',
            // 租户维护的 model_config 必须被忽略
            'model_config' => ['preferred_provider' => 'openai', 'preferred_model' => 'gpt-4o'],
        ]);

        $config = $this->resolveModelConfig($agent);

        $this->assertEquals('bailian', $config['preferred_provider']);
        $this->assertEquals('qwen-flash', $config['preferred_model']);
    }

    public function test_secretary_bypass_disabled_falls_back_to_agent_config(): void
    {
        config(['ai.secretary.enabled' => false]);

        $agent = new Agent([
            'role' => 'system_secretary',
            'model_config' => ['preferred_model' => 'gpt-4o'],
        ]);

        $config = $this->resolveModelConfig($agent);

        $this->assertEquals('gpt-4o', $config['preferred_model']);
    }

    public function test_non_secretary_agent_uses_own_model_config(): void
    {
        config(['ai.secretary.enabled' => true, 'ai.secretary.model' => 'qwen-flash']);

        $agent = new Agent([
            'role' => 'customer_service',
            'model_config' => ['preferred_model' => 'gpt-4o-mini'],
        ]);

        $config = $this->resolveModelConfig($agent);

        $this->assertEquals('gpt-4o-mini', $config['preferred_model']);
    }

    // ---------- secretary:install ----------

    public function test_install_creates_secretary_for_tenant(): void
    {
        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);

        $this->artisan('secretary:install', ['--tenant' => '1001'])->assertSuccessful();

        $this->assertEquals(1, Agent::where('tenant_id', 1001)->where('role', 'system_secretary')->count());
    }

    public function test_install_is_idempotent(): void
    {
        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);

        $this->artisan('secretary:install', ['--tenant' => '1001'])->assertSuccessful();
        $this->artisan('secretary:install', ['--tenant' => '1001'])->assertSuccessful();

        $this->assertEquals(1, Agent::where('tenant_id', 1001)->where('role', 'system_secretary')->count());
    }

    public function test_install_all_tenants_when_no_option(): void
    {
        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->artisan('secretary:install')->assertSuccessful();

        // Agent 查询受租户作用域约束，逐租户验证
        TenantContext::setTenantId('1001');
        $this->assertEquals(1, Agent::where('role', 'system_secretary')->count());

        TenantContext::setTenantId('1002');
        $this->assertEquals(1, Agent::where('role', 'system_secretary')->count());
    }
}
