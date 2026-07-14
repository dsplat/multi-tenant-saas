<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Auth\Models\Permission;
use MultiTenantSaas\Modules\Auth\Models\Role;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * RbacService 单元测试
 *
 * 覆盖：角色创建、权限创建与分配、角色权限查询（租户隔离）、权限验证
 */
class RbacServiceTest extends TestCase
{
    protected array $uses = [RbacModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        // 使用 name 作为查找键，避免 permission_id 冲突
        Permission::unguarded(function () {
            Permission::updateOrCreate(
                ['name' => 'tenant.view'],
                ['display_name' => 'View Tenant', 'group' => 'tenant']
            );
            Permission::updateOrCreate(
                ['name' => 'tenant.create'],
                ['display_name' => 'Create Tenant', 'group' => 'tenant']
            );
            Permission::updateOrCreate(
                ['name' => 'member.view'],
                ['display_name' => 'View Members', 'group' => 'member']
            );
            Permission::updateOrCreate(
                ['name' => 'member.manage'],
                ['display_name' => 'Manage Members', 'group' => 'member']
            );
        });
    }

    // ---------- 角色创建 ----------

    public function test_create_role_for_tenant(): void
    {
        $role = RbacService::createRole(1001, 'editor', 'Editor', 'Content editor role');

        $this->assertNotNull($role);
        $this->assertEquals('editor', $role->name);
        $this->assertEquals(1001, $role->tenant_id);
        $this->assertFalse($role->is_system);
    }

    public function test_create_role_without_permissions(): void
    {
        $role = RbacService::createRole(1002, 'viewer', 'Viewer', 'Read-only role');

        $this->assertEquals('viewer', $role->name);
        $this->assertEquals(1002, $role->tenant_id);
    }

    public function test_create_system_role_manually(): void
    {
        Role::unguarded(function () {
            Role::create([
                'role_id' => 100,
                'tenant_id' => null,
                'name' => 'super_admin',
                'display_name' => 'Super Admin',
                'is_system' => true,
            ]);
        });

        $role = Role::find(100);
        $this->assertTrue($role->is_system);
    }

    // ---------- 权限分配 ----------

    public function test_assign_permissions_to_role(): void
    {
        $role = RbacService::createRole(1001, 'editor2', 'Editor2', '');

        $role->grantPermission(1);
        $role->grantPermission(3);

        $permissions = RbacService::getRolePermissions($role->role_id);
        $this->assertCount(2, $permissions);
        $this->assertContains('tenant.view', $permissions);
        $this->assertContains('member.view', $permissions);
    }

    public function test_update_role_permissions_throws_for_system_role(): void
    {
        Role::unguarded(function () {
            Role::create([
                'role_id' => 200,
                'tenant_id' => null,
                'name' => 'admin',
                'display_name' => 'Admin',
                'is_system' => true,
            ]);
        });

        $this->expectException(\RuntimeException::class);
        RbacService::updateRolePermissions(200, [1]);
    }

    // ---------- 角色删除 ----------

    public function test_delete_custom_role(): void
    {
        $role = RbacService::createRole(1001, 'deletable', 'Deletable', 'To be deleted');

        RbacService::deleteRole($role->role_id);

        $this->assertNull(Role::find($role->role_id));
    }

    public function test_delete_role_throws_for_system_role(): void
    {
        Role::unguarded(function () {
            Role::create([
                'role_id' => 300,
                'tenant_id' => null,
                'name' => 'system_admin',
                'display_name' => 'System Admin',
                'is_system' => true,
            ]);
        });

        $this->expectException(\RuntimeException::class);
        RbacService::deleteRole(300);
    }

    public function test_delete_role_clears_user_assignments(): void
    {
        $role = RbacService::createRole(1001, 'assignable', 'Assignable', 'To be deleted');

        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9001,
            'tenant_id' => 1001,
            'user_id' => 2001,
            'role' => 'custom',
            'role_id' => $role->role_id,
            'is_active' => true,
        ]);

        RbacService::deleteRole($role->role_id);

        $tenantUser = DB::table('tenant_users')->where('tenant_user_id', 9001)->first();
        $this->assertNull($tenantUser->role_id);
    }

    // ---------- 角色权限查询 ----------

    public function test_get_role_permissions_returns_permission_names(): void
    {
        $role = RbacService::createRole(1001, 'perms_test', 'Perms Test', '');
        $role->grantPermission(1);
        $role->grantPermission(3);

        $permissions = RbacService::getRolePermissions($role->role_id);

        $this->assertIsArray($permissions);
        $this->assertCount(2, $permissions);
        $this->assertContains('tenant.view', $permissions);
        $this->assertContains('member.view', $permissions);
    }

    public function test_get_role_permissions_returns_empty_for_role_without_permissions(): void
    {
        $role = RbacService::createRole(1001, 'no_perms', 'No Perms', '');

        $permissions = RbacService::getRolePermissions($role->role_id);

        $this->assertIsArray($permissions);
        $this->assertEmpty($permissions);
    }

    public function test_clear_role_cache_refreshes_permissions(): void
    {
        $role = RbacService::createRole(1001, 'cached', 'Cached', '');

        $role->grantPermission(1);

        $permissions = RbacService::getRolePermissions($role->role_id);
        $this->assertContains('tenant.view', $permissions);

        // Add another permission without refreshing cache
        $role->grantPermission(3);

        // Clear cache and re-fetch
        RbacService::clearRoleCache($role->role_id);
        $permissions = RbacService::getRolePermissions($role->role_id);
        $this->assertCount(2, $permissions);
        $this->assertContains('member.view', $permissions);
    }

    // ---------- 租户角色查询（租户隔离）----------

    public function test_get_tenant_roles_returns_system_and_tenant_roles(): void
    {
        Role::unguarded(function () {
            Role::create([
                'role_id' => 500,
                'tenant_id' => null,
                'name' => 'system_role',
                'display_name' => 'System Role',
                'is_system' => true,
            ]);
        });

        RbacService::createRole(1001, 'tenant_role_a', 'Tenant Role A');
        RbacService::createRole(1002, 'tenant_role_b', 'Tenant Role B');

        $roles1001 = RbacService::getTenantRoles(1001);
        $names = $roles1001->pluck('name')->toArray();
        $this->assertContains('system_role', $names);
        $this->assertContains('tenant_role_a', $names);
        $this->assertNotContains('tenant_role_b', $names);

        $roles1002 = RbacService::getTenantRoles(1002);
        $this->assertContains('tenant_role_b', $roles1002->pluck('name')->toArray());
        $this->assertNotContains('tenant_role_a', $roles1002->pluck('name')->toArray());
    }

    // ---------- 权限验证 ----------

    public function test_check_role_permission_with_role_id(): void
    {
        $role = RbacService::createRole(1001, 'checker', 'Checker', '');
        $role->grantPermission(1);
        $role->grantPermission(3);

        $this->assertTrue(RbacService::checkRolePermission($role->role_id, 'tenant.view'));
        $this->assertTrue(RbacService::checkRolePermission($role->role_id, 'member.view'));
        $this->assertFalse(RbacService::checkRolePermission($role->role_id, 'tenant.create'));
    }

    public function test_check_role_permission_returns_false_for_unknown_permission(): void
    {
        $role = RbacService::createRole(1001, 'checker2', 'Checker2', '');
        $role->grantPermission(1);

        $this->assertFalse(RbacService::checkRolePermission($role->role_id, 'nonexistent.permission'));
    }

    // ---------- 权限分组查询 ----------

    public function test_get_all_permissions_grouped(): void
    {
        $grouped = RbacService::getAllPermissionsGrouped();

        $this->assertArrayHasKey('tenant', $grouped);
        $this->assertArrayHasKey('member', $grouped);
        $this->assertCount(2, $grouped['tenant']);
        $this->assertCount(2, $grouped['member']);
    }
}
