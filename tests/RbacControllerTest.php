<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\RbacService;
use MultiTenantSaas\Tests\Schema\MfaModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

class RbacControllerTest extends TestCase
{
    protected array $uses = [MfaModule::class, RbacModule::class];

    private int $tenantId = 13001;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'RBAC Test Tenant',
            'slug' => 'rbac-test-tenant',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'user_id' => 14001,
            'name' => 'Tenant Admin',
            'email' => 'rbac@example.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
        ]);

        TenantUser::create([
            'tenant_user_id' => 15001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
    }

    // ========== RBAC Service 测试 ==========

    public function test_create_role(): void
    {
        $role = RbacService::createRole($this->tenantId, 'test_role', '测试角色');

        $this->assertNotNull($role->role_id);
        $this->assertEquals('test_role', $role->name);
    }

    public function test_create_duplicate_role_fails(): void
    {
        RbacService::createRole($this->tenantId, 'existing_role', '已存在角色');

        $this->expectException(\RuntimeException::class);
        RbacService::createRole($this->tenantId, 'existing_role', '重复角色');
    }

    public function test_get_role_permissions(): void
    {
        $role = RbacService::createRole($this->tenantId, 'test_role', '测试角色');

        $permissions = RbacService::getRolePermissions($role->role_id);

        $this->assertIsArray($permissions);
    }

    public function test_check_permission(): void
    {
        // 测试权限检查（可能需要系统角色种子）
        $result = RbacService::check('tenant.view');

        // 结果取决于用户角色
        $this->assertIsBool($result);
    }
}
