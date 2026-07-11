<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Models\Role;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * 租户引导式注册（Onboarding）验收测试
 *
 * 覆盖 5 步注册流程、断点续填、自动初始化、试用期调用、欢迎事件等场景。
 * 依赖 TenantOnboardingService（TASK-009a）与 Controller/路由（TASK-009b）已就位。
 */
class TenantOnboardingTest extends TestCase
{
    protected array $uses = [BillingModule::class, RbacModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        // 试用期流程依赖 SubscriptionPlan，预先创建
        SubscriptionPlan::unguarded(function () {
            SubscriptionPlan::create([
                'subscription_plan_id' => 1,
                'name' => 'free',
                'display_name' => 'Free Plan',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'trial_days' => 0,
                'is_active' => true,
                'sort_order' => 0,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 2,
                'name' => 'basic',
                'display_name' => 'Basic Plan',
                'price_monthly' => 99,
                'price_yearly' => 999,
                'trial_days' => 14,
                'is_active' => true,
                'sort_order' => 1,
            ]);
        });
    }

    // ---------- 注册启动 ----------

    public function test_can_start_registration(): void
    {
        $response = $this->postJson('/api/v1/tenants/register', [
            'name' => 'Acme Corp',
            'admin_email' => 'admin@acme.test',
            'password' => 'Password123',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['auth_token', 'current_step']])
            ->assertJson(['data' => ['current_step' => 1]]);
    }

    public function test_register_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/tenants/register', []);

        $response->assertStatus(422);
    }

    // ---------- 步骤提交 ----------

    public function test_can_submit_step2_domain(): void
    {
        $token = $this->startRegistration();

        $response = $this->postJson('/api/v1/tenants/onboarding/2', [
            'auth_token' => $token,
            'domain_type' => 'subdomain',
            'subdomain' => 'acme',
        ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_can_submit_step3_plan_with_trial(): void
    {
        $token = $this->startRegistration();
        $this->submitStep($token, 2, ['domain_type' => 'subdomain', 'subdomain' => 'acme']);

        // 选择带试用天数的 basic 套餐并启用试用
        $response = $this->postJson('/api/v1/tenants/onboarding/3', [
            'auth_token' => $token,
            'plan_id' => 2,
            'start_trial' => true,
        ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_can_submit_step4_skip_payment_for_trial(): void
    {
        $token = $this->startRegistration();
        $this->submitStep($token, 2, ['domain_type' => 'subdomain', 'subdomain' => 'acme']);
        $this->submitStep($token, 3, ['plan_id' => 2, 'start_trial' => true]);

        // 试用场景跳过支付
        $response = $this->postJson('/api/v1/tenants/onboarding/4', [
            'auth_token' => $token,
            'payment_method' => 'none',
        ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ---------- 状态查询与断点续填 ----------

    public function test_can_get_onboarding_status(): void
    {
        $token = $this->startRegistration();

        $response = $this->postJson('/api/v1/tenants/onboarding/status', ['auth_token' => $token]);

        $response->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['current_step']]);
    }

    public function test_can_resume_from_interrupted_step(): void
    {
        // 仅完成 step1（注册启动），查询进度应从 step2 恢复
        $token = $this->startRegistration();

        $response = $this->postJson('/api/v1/tenants/onboarding/status', ['auth_token' => $token]);

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.current_step'));
    }

    // ---------- 异常场景 ----------

    public function test_cannot_skip_steps(): void
    {
        $token = $this->startRegistration(); // 仅 step1

        // 直接提交 step3，跳过 step2，应被拒绝
        $response = $this->postJson('/api/v1/tenants/onboarding/3', [
            'auth_token' => $token,
            'plan_id' => 2,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    public function test_cannot_complete_without_all_steps(): void
    {
        $token = $this->startRegistration(); // 仅 step1，未完成全部步骤

        $response = $this->postJson('/api/v1/tenants/onboarding/complete', [
            'auth_token' => $token,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    public function test_duplicate_registration_rejected(): void
    {
        $this->startRegistration(['admin_email' => 'dup@acme.test']);

        $response = $this->postJson('/api/v1/tenants/register', [
            'name' => 'Another Corp',
            'admin_email' => 'dup@acme.test',
            'password' => 'Password123',
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    public function test_expired_token_rejected(): void
    {
        // 使用不存在的 token 模拟过期/失效
        $response = $this->postJson('/api/v1/tenants/onboarding/status', ['auth_token' => 'expired-or-invalid-token']);

        $response->assertStatus(404);
    }

    // ---------- 完成与自动初始化 ----------

    public function test_complete_creates_tenant_with_defaults(): void
    {
        Event::fake([TenantCreated::class]);

        $token = $this->runFullFlowWithoutTrial();

        $response = $this->postJson('/api/v1/tenants/onboarding/complete', [
            'auth_token' => $token,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['onboarding_step', 'onboarding_completed', 'trial_active']])
            ->assertJson(['data' => ['trial_active' => false]]);

        $tenantId = $response->json('data.tenant_id');
        $this->assertNotNull($tenantId, '应返回创建的租户 ID');

        $tenant = Tenant::find($tenantId);
        $this->assertNotNull($tenant, '租户记录应被创建');

        // 默认角色（admin / user）
        $this->assertGreaterThanOrEqual(
            2,
            Role::where('tenant_id', $tenantId)->count(),
            '默认角色应被创建'
        );

        // 默认管理员用户
        $this->assertNotNull(
            User::where('email', 'admin@acme.test')->first(),
            '管理员用户应被创建'
        );

        // 管理员应关联到租户
        $this->assertTrue(
            DB::table('tenant_users')->where('tenant_id', $tenantId)->exists(),
            'TenantUser 关联应被创建'
        );

        // 租户初始化设置
        $this->assertTrue(
            DB::table('tenant_settings')->where('tenant_id', $tenantId)->exists(),
            '租户配置应被初始化'
        );
    }

    public function test_complete_with_trial_calls_trial_service(): void
    {
        Event::fake([TenantCreated::class]);

        $token = $this->runFullFlowWithTrial();

        $response = $this->postJson('/api/v1/tenants/onboarding/complete', [
            'auth_token' => $token,
        ]);

        $response->assertStatus(201)
            ->assertJson(['data' => ['trial_active' => true]]);

        $tenant = Tenant::find($response->json('data.tenant_id'));
        $this->assertNotNull($tenant);
        $this->assertNotNull($tenant->trial_ends_at, 'TrialService::startTrial 应设置 trial_ends_at');
        $this->assertTrue(
            $tenant->trial_ends_at->isFuture(),
            'trial_ends_at 应为未来时间'
        );
    }

    public function test_complete_fires_tenant_created_event(): void
    {
        Event::fake([TenantCreated::class]);

        $token = $this->runFullFlowWithoutTrial();

        $this->postJson('/api/v1/tenants/onboarding/complete', [
            'auth_token' => $token,
        ]);

        Event::assertDispatched(TenantCreated::class);
    }

    // ---------- 辅助方法 ----------

    private function startRegistration(array $overrides = []): string
    {
        $response = $this->postJson('/api/v1/tenants/register', array_merge([
            'name' => 'Acme Corp',
            'admin_email' => 'admin@acme.test',
            'password' => 'Password123',
        ], $overrides));

        $response->assertSuccessful();

        return $response->json('data.auth_token');
    }

    private function submitStep(string $token, int $step, array $data): array
    {
        $response = $this->postJson("/api/v1/tenants/onboarding/{$step}", array_merge([
            'auth_token' => $token,
        ], $data));

        $response->assertSuccessful();

        return $response->json('data') ?? [];
    }

    private function runFullFlowWithoutTrial(): string
    {
        $token = $this->startRegistration();
        $this->submitStep($token, 2, ['domain_type' => 'subdomain', 'subdomain' => 'acme']);
        $this->submitStep($token, 3, ['plan_id' => 1, 'start_trial' => false]);
        $this->submitStep($token, 4, ['payment_method' => 'none']);

        return $token;
    }

    private function runFullFlowWithTrial(): string
    {
        $token = $this->startRegistration();
        $this->submitStep($token, 2, ['domain_type' => 'subdomain', 'subdomain' => 'acme']);
        $this->submitStep($token, 3, ['plan_id' => 2, 'start_trial' => true]);
        $this->submitStep($token, 4, ['payment_method' => 'none']);

        return $token;
    }
}
