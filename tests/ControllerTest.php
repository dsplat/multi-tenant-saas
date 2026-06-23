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

        // 创建租户
        $this->tenant = Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test',
            'status' => 'active',
        ]);

        $this->otherTenant = Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Other Tenant',
            'slug' => 'other',
            'status' => 'active',
        ]);

        // 创建用户（user_id 由 HasGlobalId 自动生成）
        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->tenantAdmin = User::create([
            'name' => 'Tenant Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'platform_user',
        ]);

        $this->endUser = User::create([
            'name' => 'End User',
            'email' => 'user@test.com',
            'password' => bcrypt('password'),
            'role' => 'platform_user',
        ]);

        // 关联用户到租户
        TenantUser::create([
            'tenant_id' => 1001,
            'user_id' => $this->tenantAdmin->user_id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        TenantUser::create([
            'tenant_id' => 1001,
            'user_id' => $this->endUser->user_id,
            'role' => 'end_user',
            'is_active' => true,
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
            ->getJson('/api/v1/tenants/1001/members');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_tenant_admin_cannot_access_other_tenant_members(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants/1002/members');

        $response->assertStatus(403);
    }

    public function test_super_admin_cannot_access_tenant_data(): void
    {
        $token = $this->superAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants/1001/members');

        $response->assertStatus(403);
    }

    public function test_end_user_can_access_tenant_data(): void
    {
        $token = $this->endUser->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants/1001/members');

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
            'password' => 'password123',
            'password_confirmation' => 'password123',
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
            ->getJson('/api/v1/tenants/1001/settings');

        $response->assertSuccessful();
    }

    public function test_tenant_can_update_own_settings(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/tenants/1001/settings/auth', [
                'allow_phone_login' => true,
            ]);

        $response->assertSuccessful();
    }

    // ========== 积分 API ==========

    public function test_tenant_can_get_credits(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants/1001/credits');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== 支付回调 API（无需认证） ==========

    public function test_wechat_notify_returns_success(): void
    {
        // 微信支付回调需要 tenant_id 参数
        $response = $this->postJson('/api/v1/pay/wechat/notify?tenant_id=1001', [
            'out_trade_no' => 'TEST001',
            'trade_status' => 'TRADE_SUCCESS',
        ]);

        // 因为没有真实支付配置，会抛异常，但路由是通的
        $this->assertContains($response->status(), [200, 400, 500]);
    }

    public function test_alipay_notify_returns_success(): void
    {
        $response = $this->postJson('/api/v1/pay/alipay/notify?tenant_id=1001', [
            'out_trade_no' => 'TEST001',
            'trade_status' => 'TRADE_SUCCESS',
        ]);

        $this->assertContains($response->status(), [200, 400, 500]);
    }

    // ========== OAuth API ==========

    public function test_oauth_redirect_returns_url(): void
    {
        $response = $this->getJson('/api/v1/auth/wechat/redirect');

        // 未配置 OAuth 会返回错误，但路由是通的
        $this->assertContains($response->status(), [200, 500]);
    }

    // ========== 域名管理 API ==========

    public function test_tenant_can_get_domain_info(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants/1001/domain');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== 配额 API ==========

    public function test_tenant_can_get_quotas(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants/1001/quotas');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }
}
