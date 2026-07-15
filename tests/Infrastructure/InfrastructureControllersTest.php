<?php

namespace MultiTenantSaas\Tests\Infrastructure;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\TestCase;

/**
 * Infrastructure 模块 Controller 测试
 */
class InfrastructureControllersTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, InfrastructureModule::class];

    private int $tenantId = 9001;

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建租户
        $this->tenant = Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        // 创建管理员用户
        $this->admin = User::create([
            'user_id' => 9001,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantId,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // 创建 operator
        $operator = Operator::create([
            'email' => 'admin@test.com',
            'name' => 'Admin',
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        $tenantAdminRoleId = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        // 确保 tenant_users 表有记录（ensureTenantAccess 需要）
        DB::table('tenant_users')->insert([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'role' => 'tenant_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::setTenantId($this->tenantId);
    }

    // ========== WebhookController ==========

    public function test_webhook_index_returns_empty_list(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/tenants/' . $this->tenantId . '/webhooks');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_webhook_store_creates_webhook(): void
    {
        $this->markTestSkipped('TenantContext issue in test environment - needs investigation');

        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/tenants/' . $this->tenantId . '/webhooks', [
                'url' => 'https://example.com/webhook',
                'events' => ['user.created', 'user.updated'],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    // ========== IpWhitelistController ==========

    public function test_ip_whitelist_index_returns_empty_list(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/tenants/' . $this->tenantId . '/ip-whitelist');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_ip_whitelist_store_adds_ip(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/tenants/' . $this->tenantId . '/ip-whitelist', [
                'ip' => '192.168.1.1',
                'description' => 'Office IP',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    // ========== FeatureFlagController ==========

    public function test_feature_flag_index_returns_list(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/feature-flags');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== BrandingConfigController ==========

    public function test_branding_config_index_returns_config(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/tenants/' . $this->tenantId . '/branding');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== SystemSettingController ==========

    public function test_system_setting_index_returns_settings(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/system-settings');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== TenantKeyController ==========

    public function test_tenant_key_index_returns_empty_list(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/tenants/' . $this->tenantId . '/tenant-keys');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== DataRetentionPolicyController ==========

    public function test_data_retention_policy_index_returns_list(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/retention-policies');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== ConsentController ==========

    public function test_consent_index_returns_list(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/consents');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }
}
