<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\MfaModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

class TenantControllerTest extends TestCase
{
    protected array $uses = [MfaModule::class, RbacModule::class];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'user_id' => 12001,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        // 创建平台级 operator
        $operator = Operator::create([
            'email' => 'admin@example.com',
            'name' => 'Super Admin',
            'scope' => 'platform',
            'is_active' => true,
        ]);

        // 获取 super_admin 角色 ID
        $superAdminRoleId = \DB::table('roles')
            ->where('name', 'super_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        // 创建 operator_tenants 映射
        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => 9007199254740991, // 平台租户
            'user_id' => $this->admin->user_id,
            'role' => 'super_admin',
            'role_id' => $superAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);
    }

    // ========== 租户列表 ==========

    public function test_index_tenants(): void
    {
        Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);

        Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/tenants');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== 创建租户 ==========

    public function test_store_tenant(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/tenants', [
                'name' => 'New Tenant',
                'slug' => 'new-tenant',
                'subscription_plan' => 'free',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', 'New Tenant');

        $this->assertDatabaseHas('tenants', [
            'slug' => 'new-tenant',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    public function test_store_tenant_with_contact_info(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/tenants', [
                'name' => 'Contact Tenant',
                'slug' => 'contact-tenant',
                'contact_name' => 'John Doe',
                'contact_email' => 'john@example.com',
                'contact_phone' => '+1234567890',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tenants', [
            'slug' => 'contact-tenant',
            'contact_name' => 'John Doe',
            'contact_email' => 'john@example.com',
        ]);
    }

    public function test_store_tenant_requires_name(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/tenants', [
                'slug' => 'no-name',
            ]);

        $response->assertStatus(422);
    }

    public function test_store_tenant_requires_unique_slug(): void
    {
        Tenant::create([
            'name' => 'Existing',
            'slug' => 'existing-slug',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/tenants', [
                'name' => 'Duplicate',
                'slug' => 'existing-slug',
            ]);

        $response->assertStatus(422);
    }

    // ========== 租户详情 ==========

    public function test_show_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/tenants/{$tenant->tenant_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', 'Test Tenant');
    }

    public function test_show_nonexistent_tenant(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/tenants/99999999');

        $response->assertStatus(404);
    }

    // ========== 更新租户 ==========

    public function test_update_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Original Name',
            'slug' => 'original-slug',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/tenants/{$tenant->tenant_id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', 'Updated Name');
    }

    // ========== 删除租户 ==========

    public function test_destroy_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'To Delete',
            'slug' => 'to-delete',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/tenants/{$tenant->tenant_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== 暂停/激活租户 ==========

    public function test_suspend_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Active Tenant',
            'slug' => 'active-tenant',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$tenant->tenant_id}/suspend");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tenants', [
            'tenant_id' => $tenant->tenant_id,
            'status' => 'suspended',
        ]);
    }

    public function test_activate_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Suspended Tenant',
            'slug' => 'suspended-tenant',
            'status' => 'suspended',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$tenant->tenant_id}/activate");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tenants', [
            'tenant_id' => $tenant->tenant_id,
            'status' => 'active',
        ]);
    }

    // ========== 权限测试 ==========

    public function test_non_admin_cannot_create_tenant(): void
    {
        $user = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'role' => 'end_user',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/tenants', [
                'name' => 'New Tenant',
                'slug' => 'new-tenant',
            ]);

        $response->assertStatus(403);
    }
}
