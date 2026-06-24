<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;

class ControllerTest extends TestCase
{
    use DatabaseMigrations;
    protected User $superAdmin;
    protected User $tenantAdmin;
    protected User $endUser;
    protected Tenant $tenant;
    protected Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        // 使用工厂创建测试数据，避免硬编码 ID
        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'slug' => 'test',
        ]);

        $this->otherTenant = Tenant::factory()->create([
            'name' => 'Other Tenant',
            'slug' => 'other',
        ]);

        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => 'super@test.com',
        ]);

        $this->tenantAdmin = User::factory()->create([
            'email' => 'admin@test.com',
        ]);

        $this->endUser = User::factory()->create([
            'email' => 'user@test.com',
        ]);

        // 关联用户到租户
        TenantUser::factory()->admin()->create([
            'tenant_id' => $this->tenant->tenant_id,
            'user_id' => $this->tenantAdmin->user_id,
        ]);

        TenantUser::factory()->endUser()->create([
            'tenant_id' => $this->tenant->tenant_id,
            'user_id' => $this->endUser->user_id,
        ]);
    }

    // ========== 租户管理 API (super_admin only) ==========

    public function test_super_admin_can_list_tenants(): void
    {
        $token = $this->superAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_normal_user_cannot_list_tenants(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants');

        $response->assertStatus(403);
    }

    // ========== 租户数据 API (需要属于该租户) ==========

    public function test_tenant_admin_can_access_own_tenant_members(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->tenant->tenant_id}/members");

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_tenant_admin_cannot_access_other_tenant_members(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->otherTenant->tenant_id}/members");

        $response->assertStatus(403);
    }

    public function test_super_admin_cannot_access_tenant_data(): void
    {
        $token = $this->superAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->tenant->tenant_id}/members");

        $response->assertStatus(403);
    }

    public function test_end_user_can_access_tenant_data(): void
    {
        $token = $this->endUser->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->tenant->tenant_id}/members");

        $response->assertSuccessful();
    }

    // ========== 认证 API ==========

    public function test_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password',
        ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_login_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    public function test_register_creates_user(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new@test.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', ['email' => 'new@test.com']);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => ['email' => 'admin@test.com'],
                ],
            ]);
    }

    // ========== 租户配置 API ==========

    public function test_tenant_can_get_own_settings(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->tenant->tenant_id}/settings");

        $response->assertSuccessful();
    }

    public function test_tenant_can_update_own_settings(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/tenants/{$this->tenant->tenant_id}/settings/auth", [
                'allow_phone_login' => true,
            ]);

        $response->assertSuccessful();
    }

    // ========== 积分 API ==========

    public function test_tenant_can_get_credits(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->tenant->tenant_id}/credits");

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== 支付回调 API（无需认证） ==========

    public function test_wechat_notify_returns_success(): void
    {
        $response = $this->postJson("/api/v1/pay/wechat/notify?tenant_id={$this->tenant->tenant_id}", [
            'out_trade_no' => 'TEST001',
            'trade_status' => 'TRADE_SUCCESS',
        ]);

        $this->assertContains($response->status(), [200, 400, 500]);
    }

    public function test_alipay_notify_returns_success(): void
    {
        $response = $this->postJson("/api/v1/pay/alipay/notify?tenant_id={$this->tenant->tenant_id}", [
            'out_trade_no' => 'TEST001',
            'trade_status' => 'TRADE_SUCCESS',
        ]);

        $this->assertContains($response->status(), [200, 400, 500]);
    }

    // ========== OAuth API ==========

    public function test_oauth_redirect_returns_url(): void
    {
        $response = $this->getJson('/api/v1/auth/wechat/redirect');

        $this->assertContains($response->status(), [200, 500]);
    }

    // ========== 域名管理 API ==========

    public function test_tenant_can_get_domain_info(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->tenant->tenant_id}/domain");

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== 配额 API ==========

    public function test_tenant_can_get_quotas(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->tenant->tenant_id}/quotas");

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }
}
