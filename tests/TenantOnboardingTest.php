<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Modules\Auth\Models\Role;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantOnboardingService;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * 租户引导式注册（Onboarding）验收测试
 *
 * 覆盖 5 步注册流程、断点续填、自动初始化、欢迎事件等场景。
 * 流程：start(step1) → step2(domain) → step3(plan) → step4(payment) → complete
 *
 * 路由认证模型：
 *   POST /start   — 公开（无需认证）
 *   POST /{step}  — operator.auth（Bearer token）
 *   POST /status  — operator.auth
 *   POST /complete — operator.auth
 */
class TenantOnboardingTest extends TestCase
{
    protected array $uses = [BillingModule::class, RbacModule::class];

    private Operator $operator;

    private string $operatorToken;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建 Operator 并生成 Sanctum token（operator.auth 中间件需要 Bearer token）
        $this->operator = Operator::create([
            'email' => 'onboarding@platform.com',
            'name' => 'Onboarding Operator',
            'scope' => 'platform',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->operatorToken = $this->operator->createToken('onboarding-test')->plainTextToken;

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
        $response = $this->postJson('/api/v1/tenants/onboarding/start', [
            'name' => 'Acme Corp',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['onboarding_token', 'current_step']])
            ->assertJson(['data' => ['current_step' => 2]]);
    }

    public function test_register_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/tenants/onboarding/start', []);

        $response->assertStatus(422);
    }

    // ---------- 步骤提交 ----------

    public function test_can_submit_step2_domain(): void
    {
        $token = $this->startRegistration();

        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/2', [
            'onboarding_token' => $token,
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
        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/3', [
            'onboarding_token' => $token,
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
        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/4', [
            'onboarding_token' => $token,
            'payment_method' => 'none',
        ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ---------- 状态查询与断点续填 ----------

    public function test_can_get_onboarding_status(): void
    {
        $token = $this->startRegistration();

        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/status', [
            'onboarding_token' => $token,
        ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['current_step', 'completed_steps', 'step_names']]);
    }

    public function test_can_resume_from_interrupted_step(): void
    {
        // 仅完成 step1（注册启动），查询进度应从 step2 恢复
        $token = $this->startRegistration();

        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/status', [
            'onboarding_token' => $token,
        ]);

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.current_step'));
    }

    // ---------- 异常场景 ----------

    public function test_cannot_skip_steps(): void
    {
        $token = $this->startRegistration(); // 仅 step1

        // 直接提交 step3，跳过 step2，应被拒绝
        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/3', [
            'onboarding_token' => $token,
            'plan_id' => 2,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    public function test_cannot_complete_without_all_steps(): void
    {
        $token = $this->startRegistration(); // 仅 step1，未完成全部步骤

        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/complete', [
            'onboarding_token' => $token,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    public function test_duplicate_registration_rejected(): void
    {
        // 同一 Operator 同时只允许一个进行中的会话（服务层校验）
        $service = $this->app->make(TenantOnboardingService::class);
        $service->startRegistration(['name' => 'First Corp'], $this->operator->operator_id);

        $this->expectException(\RuntimeException::class);
        $service->startRegistration(['name' => 'Second Corp'], $this->operator->operator_id);
    }

    public function test_expired_token_rejected(): void
    {
        // 使用不存在的 token 模拟过期/失效
        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/status', [
            'onboarding_token' => 'expired-or-invalid-token',
        ]);

        $response->assertStatus(404);
    }

    // ---------- 完成与自动初始化 ----------

    public function test_complete_creates_tenant_with_defaults(): void
    {
        Event::fake([TenantCreated::class]);

        $token = $this->runFullFlowWithoutTrial();

        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/complete', [
            'onboarding_token' => $token,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['tenant_id', 'name', 'slug', 'status', 'onboarding_step', 'onboarding_completed']])
            ->assertJson(['data' => ['status' => 'pending_approval', 'onboarding_completed' => true]]);

        $tenantId = $response->json('data.tenant_id');
        $this->assertNotNull($tenantId, '应返回创建的租户 ID');

        $tenant = Tenant::find($tenantId);
        $this->assertNotNull($tenant, '租户记录应被创建');
        $this->assertEquals('pending_approval', $tenant->status);

        // 默认角色（tenant_admin / end_user）
        $this->assertGreaterThanOrEqual(
            2,
            Role::where('tenant_id', $tenantId)->count(),
            '默认角色应被创建'
        );

        // 租户初始化设置
        $this->assertTrue(
            DB::table('tenant_settings')->where('tenant_id', $tenantId)->exists(),
            '租户配置应被初始化'
        );
    }

    public function test_complete_with_trial_plan(): void
    {
        Event::fake([TenantCreated::class]);

        $token = $this->runFullFlowWithTrial();

        $response = $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/complete', [
            'onboarding_token' => $token,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'data' => ['status' => 'pending_approval']]);

        $tenant = Tenant::find($response->json('data.tenant_id'));
        $this->assertNotNull($tenant);
        // 试用在审核通过后由平台激活，onboarding 阶段仅记录 plan
        $this->assertEquals('basic', $tenant->subscription_plan);
    }

    public function test_complete_fires_tenant_created_event(): void
    {
        Event::fake([TenantCreated::class]);

        $token = $this->runFullFlowWithoutTrial();

        $this->withOperatorAuth()->postJson('/api/v1/tenants/onboarding/complete', [
            'onboarding_token' => $token,
        ]);

        Event::assertDispatched(TenantCreated::class);
    }

    // ---------- 辅助方法 ----------

    /**
     * 设置 Operator Bearer token 认证头
     */
    private function withOperatorAuth(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->operatorToken}");
    }

    /**
     * 启动注册流程（公开接口，无需认证）
     */
    private function startRegistration(array $overrides = []): string
    {
        $response = $this->postJson('/api/v1/tenants/onboarding/start', array_merge([
            'name' => 'Acme Corp',
        ], $overrides));

        $response->assertSuccessful();

        return $response->json('data.onboarding_token');
    }

    /**
     * 提交指定步骤（需 operator.auth）
     */
    private function submitStep(string $token, int $step, array $data): array
    {
        $response = $this->withOperatorAuth()->postJson("/api/v1/tenants/onboarding/{$step}", array_merge([
            'onboarding_token' => $token,
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
