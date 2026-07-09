<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\ModuleManager;

class ModuleControllerTest extends TestCase
{
    private int $tenantId = 1001;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Module Test Tenant',
            'slug' => 'module-test-tenant',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'user_id' => 2001,
            'name' => 'Test User',
            'email' => 'module-test@example.com',
            'password' => bcrypt('password'),
            'role' => 'platform_admin',
        ]);

        \MultiTenantSaas\Models\TenantUser::create([
            'tenant_user_id' => 3001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->user->user_id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        TenantContext::setTenantId($this->tenantId);
    }

    // ========== 系统级模块管理 ==========

    public function test_admin_can_list_modules(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/modules');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    '*' => ['name', 'version', 'description', 'status', 'dependencies', 'conflicts', 'priority', 'tenant_toggleable'],
                ],
            ]);
    }

    public function test_admin_can_enable_module(): void
    {
        $manager = app(ModuleManager::class);

        // 确保 api-token 模块是禁用的
        if ($manager->isEnabled('api-token')) {
            $manager->disable('api-token');
        }

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/admin/modules/api-token/enable');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($manager->isEnabled('api-token'));
    }

    public function test_admin_can_disable_module(): void
    {
        $manager = app(ModuleManager::class);

        // 确保 domain 模块是启用的
        if (! $manager->isEnabled('domain')) {
            $manager->enable('domain');
        }

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/admin/modules/domain/disable');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertFalse($manager->isEnabled('domain'));
    }

    public function test_enable_nonexistent_module_returns_404(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/admin/modules/nonexistent/enable');

        $response->assertNotFound()
            ->assertJson(['success' => false]);
    }

    public function test_disable_nonexistent_module_returns_404(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/admin/modules/nonexistent/disable');

        $response->assertNotFound()
            ->assertJson(['success' => false]);
    }

    // ========== 租户级模块管理 ==========

    public function test_tenant_can_list_modules(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/modules");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    '*' => ['name', 'version', 'description', 'system_status', 'tenant_status', 'tenant_toggleable', 'enabled'],
                ],
            ]);
    }

    public function test_tenant_list_only_shows_system_enabled_modules(): void
    {
        $manager = app(ModuleManager::class);

        // 禁用 api-token 系统级
        $manager->disable('api-token');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/modules");

        $response->assertOk();

        $modules = collect($response->json('data'));
        $this->assertFalse($modules->contains('name', 'api-token'));
    }

    public function test_tenant_can_enable_toggleable_module(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/modules/form/enable");

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_tenant_can_disable_toggleable_module(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/modules/form/disable");

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_tenant_cannot_toggle_non_toggleable_module(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/modules/infrastructure/enable");

        $response->assertUnprocessable()
            ->assertJson(['success' => false]);
    }

    public function test_tenant_cannot_enable_system_disabled_module(): void
    {
        $manager = app(ModuleManager::class);
        $manager->disable('form');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/modules/form/enable");

        $response->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    // ========== 租户模块自动开通 ==========

    public function test_provision_tenant_modules_creates_records(): void
    {
        $manager = app(ModuleManager::class);
        $provisioned = $manager->provisionTenantModules($this->tenantId, 'free');

        $this->assertNotEmpty($provisioned);

        // 验证 tenant_modules 表有记录
        $records = \DB::table('tenant_modules')
            ->where('tenant_id', $this->tenantId)
            ->get();

        $this->assertNotEmpty($records);
    }

    public function test_provision_respects_plan_config(): void
    {
        $manager = app(ModuleManager::class);

        // free 套餐: coupon 应该是 disabled (config 中 free.coupon = false)
        $manager->provisionTenantModules($this->tenantId, 'free');

        $couponRecord = \DB::table('tenant_modules')
            ->where('tenant_id', $this->tenantId)
            ->where('module_name', 'coupon')
            ->first();

        $this->assertNotNull($couponRecord);
        $this->assertEquals('disabled', $couponRecord->status);
    }

    public function test_provision_enterprise_enables_more_modules(): void
    {
        $manager = app(ModuleManager::class);

        // payment 系统级默认禁用, 先启用
        $manager->enable('payment');

        // enterprise 套餐: payment 应该是 enabled
        $manager->provisionTenantModules($this->tenantId, 'enterprise');

        $paymentRecord = \DB::table('tenant_modules')
            ->where('tenant_id', $this->tenantId)
            ->where('module_name', 'payment')
            ->first();

        $this->assertNotNull($paymentRecord);
        $this->assertEquals('enabled', $paymentRecord->status);
    }

    public function test_provision_only_creates_toggleable_modules(): void
    {
        $manager = app(ModuleManager::class);
        $manager->provisionTenantModules($this->tenantId, 'free');

        // infrastructure 不是 tenant_toggleable, 不应该有记录
        $infraRecord = \DB::table('tenant_modules')
            ->where('tenant_id', $this->tenantId)
            ->where('module_name', 'infrastructure')
            ->first();

        $this->assertNull($infraRecord);
    }
}
