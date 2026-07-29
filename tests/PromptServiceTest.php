<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AiPrompt;
use MultiTenantSaas\Modules\Ai\Services\Agent\PromptService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AiModule;

class PromptServiceTest extends TestCase
{
    protected array $uses = [AiModule::class];

    protected PromptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $tenantContext = $this->app->make(TenantContextContract::class);
        $this->service = new PromptService($tenantContext);
    }

    /**
     * 创建系统级 prompt（绕过 BelongsToTenant 自动填充）
     */
    private function createSystemPrompt(array $attrs): AiPrompt
    {
        $prompt = new AiPrompt;
        $prompt->fill(array_merge(['status' => 'active'], $attrs));
        $prompt->tenant_id = null;
        $prompt->saveQuietly();

        return $prompt;
    }

    // ---- 三级解析链 ----

    public function test_resolve_system_level_prompt(): void
    {
        $this->createSystemPrompt([
            'operator_id' => null,
            'role' => 'customer_service',
            'name' => 'system-cs',
            'system_prompt' => '你是系统级客服。',
        ]);

        $result = $this->service->resolve('customer_service');
        $this->assertSame('你是系统级客服。', $result);
    }

    public function test_tenant_overrides_system(): void
    {
        $this->createSystemPrompt([
            'operator_id' => null,
            'role' => 'customer_service',
            'name' => 'system-cs',
            'system_prompt' => '系统级客服。',
        ]);

        AiPrompt::create([
            'tenant_id' => 1001,
            'operator_id' => null,
            'role' => 'customer_service',
            'name' => 'tenant-cs',
            'system_prompt' => '租户级客服：{{tenant_name}} 专属。',
            'status' => 'active',
        ]);

        $result = $this->service->resolve('customer_service');
        $this->assertSame('租户级客服：Tenant A 专属。', $result);
    }

    public function test_operator_overrides_tenant(): void
    {
        AiPrompt::create([
            'tenant_id' => 1001,
            'operator_id' => null,
            'role' => 'sales',
            'name' => 'tenant-sales',
            'system_prompt' => '租户级销售。',
            'status' => 'active',
        ]);

        AiPrompt::create([
            'tenant_id' => 1001,
            'operator_id' => 42,
            'role' => 'sales',
            'name' => 'op-sales',
            'system_prompt' => '{{operator_name}} 的专属销售话术。',
            'status' => 'active',
        ]);

        $result = $this->service->resolve('sales', 42, ['operator_name' => 'Arthur']);
        $this->assertSame('Arthur 的专属销售话术。', $result);
    }

    public function test_returns_null_when_no_match(): void
    {
        $result = $this->service->resolve('nonexistent_role');
        $this->assertNull($result);
    }

    public function test_inactive_prompt_skipped(): void
    {
        $this->createSystemPrompt([
            'operator_id' => null,
            'role' => 'marketing',
            'name' => 'disabled',
            'system_prompt' => '不应出现。',
            'status' => 'inactive',
        ]);

        $result = $this->service->resolve('marketing');
        $this->assertNull($result);
    }

    public function test_operator_falls_through_to_tenant_when_no_operator_prompt(): void
    {
        AiPrompt::create([
            'tenant_id' => 1001,
            'operator_id' => null,
            'role' => 'analyst',
            'name' => 'tenant-analyst',
            'system_prompt' => '租户级分析师。',
            'status' => 'active',
        ]);

        // operator 99 没有专属 prompt，应降级到 tenant 级
        $result = $this->service->resolve('analyst', 99);
        $this->assertSame('租户级分析师。', $result);
    }

    // ---- 变量插值 ----

    public function test_render_interpolates_variables(): void
    {
        $template = '你好 {{operator_name}}，今天是 {{current_date}}，欢迎使用 {{agent_name}}。';
        $result = $this->service->render($template, [
            'operator_name' => 'Arthur',
            'agent_name' => '小秘书',
        ]);

        $this->assertStringContainsString('Arthur', $result);
        $this->assertStringContainsString('小秘书', $result);
        $this->assertStringContainsString(date('Y-m-d'), $result);
    }

    public function test_render_preserves_unknown_variables(): void
    {
        $template = '保留 {{unknown_var}} 不替换。';
        $result = $this->service->render($template);

        $this->assertStringContainsString('{{unknown_var}}', $result);
    }

    public function test_render_injects_tenant_name_automatically(): void
    {
        $template = '团队：{{tenant_name}}';
        $result = $this->service->render($template);

        $this->assertSame('团队：Tenant A', $result);
    }

    // ---- listByRole ----

    public function test_list_by_role_returns_all_levels(): void
    {
        $this->createSystemPrompt([
            'operator_id' => null, 'role' => 'cs',
            'name' => 'sys', 'system_prompt' => 'sys',
        ]);
        AiPrompt::create([
            'tenant_id' => 1001, 'operator_id' => null, 'role' => 'cs',
            'name' => 'tenant', 'system_prompt' => 'tenant', 'status' => 'active',
        ]);
        AiPrompt::create([
            'tenant_id' => 1001, 'operator_id' => 7, 'role' => 'cs',
            'name' => 'op', 'system_prompt' => 'op', 'status' => 'active',
        ]);

        $list = $this->service->listByRole('cs', 7);

        $this->assertCount(3, $list);
        $this->assertSame('system', $list[0]['scope']);
        $this->assertSame('tenant', $list[1]['scope']);
        $this->assertSame('operator', $list[2]['scope']);
    }
}
