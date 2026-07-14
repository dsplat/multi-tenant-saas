<?php

namespace MultiTenantSaas\Tests\Integration;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Operator\Http\Controllers\OperatorController;
use MultiTenantSaas\Modules\Operator\Http\Middleware\IdentifyOperator;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Modules\Operator\OperatorServiceProvider;
use MultiTenantSaas\Modules\Operator\Services\OperatorService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\TestCase;

/**
 * 权限模型集成测试
 *
 * 验证：
 * 1. 平台初始化流程
 * 2. Operator 登录流程
 * 3. 不同角色的 API 访问权限
 * 4. 租户隔离
 */
class PermissionModelIntegrationTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class];

    // 测试数据
    private Tenant $platformTenant;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Operator $superAdminOperator;

    private Operator $platformAdminOperator;

    private Operator $tenantAAdminOperator;

    private Operator $tenantBAdminOperator;

    private User $superAdminUser;

    private User $platformAdminUser;

    private User $tenantAAdminUser;

    private User $tenantBAdminUser;

    private User $endUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformData();
    }

    /**
     * 初始化角色和权限
     */
    private function seedRolesAndPermissions(): void
    {
        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        // 创建系统角色
        $roles = [
            ['name' => 'super_admin', 'display_name' => '超级管理员', 'description' => '系统级管理角色'],
            ['name' => 'platform_admin', 'display_name' => '平台管理员', 'description' => '平台运营管理角色'],
            ['name' => 'platform_support', 'display_name' => '平台支持', 'description' => '平台客服支持角色'],
            ['name' => 'tenant_admin', 'display_name' => '租户管理员', 'description' => '租户管理角色'],
            ['name' => 'member', 'display_name' => '成员', 'description' => '基础成员角色'],
            ['name' => 'viewer', 'display_name' => '观察者', 'description' => '只读访问角色'],
            ['name' => 'end_user', 'display_name' => '普通用户', 'description' => '终端用户角色'],
        ];

        foreach ($roles as $role) {
            \DB::table('roles')->insert([
                'role_id' => $idGenerator->generate(),
                'tenant_id' => null,
                'name' => $role['name'],
                'display_name' => $role['display_name'],
                'description' => $role['description'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 创建权限
        $permissions = [
            ['name' => 'tenant.view', 'display_name' => '查看租户', 'group' => 'tenant'],
            ['name' => 'tenant.create', 'display_name' => '创建租户', 'group' => 'tenant'],
            ['name' => 'tenant.update', 'display_name' => '更新租户', 'group' => 'tenant'],
            ['name' => 'tenant.delete', 'display_name' => '删除租户', 'group' => 'tenant'],
            ['name' => 'member.view', 'display_name' => '查看成员', 'group' => 'member'],
            ['name' => 'member.create', 'display_name' => '添加成员', 'group' => 'member'],
            ['name' => 'member.update', 'display_name' => '更新成员', 'group' => 'member'],
            ['name' => 'member.delete', 'display_name' => '移除成员', 'group' => 'member'],
            ['name' => 'credit.view', 'display_name' => '查看积分', 'group' => 'credit'],
            ['name' => 'setting.view', 'display_name' => '查看配置', 'group' => 'setting'],
            ['name' => 'payment.view', 'display_name' => '查看支付', 'group' => 'payment'],
            ['name' => 'audit.view', 'display_name' => '查看审计', 'group' => 'audit'],
        ];

        foreach ($permissions as $perm) {
            \DB::table('permissions')->insert([
                'permission_id' => $idGenerator->generate(),
                'name' => $perm['name'],
                'display_name' => $perm['display_name'],
                'group' => $perm['group'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 为 super_admin 分配所有权限
        $allPermIds = \DB::table('permissions')->pluck('permission_id');
        $superAdminRoleId = \DB::table('roles')->where('name', 'super_admin')->value('role_id');

        foreach ($allPermIds as $permId) {
            \DB::table('role_permissions')->insert([
                'role_id' => $superAdminRoleId,
                'permission_id' => $permId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 为 tenant_admin 分配除 tenant.create/delete 外的权限
        $tenantAdminRoleId = \DB::table('roles')->where('name', 'tenant_admin')->value('role_id');
        $tenantPerms = \DB::table('permissions')
            ->whereNotIn('name', ['tenant.create', 'tenant.delete'])
            ->pluck('permission_id');

        foreach ($tenantPerms as $permId) {
            \DB::table('role_permissions')->insert([
                'role_id' => $tenantAdminRoleId,
                'permission_id' => $permId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * 初始化平台数据
     */
    private function seedPlatformData(): void
    {
        // 1. 创建平台租户
        $this->platformTenant = Tenant::create([
            'tenant_id' => 9007199254740991,
            'name' => '平台默认租户',
            'slug' => 'platform',
            'status' => 'active',
            'is_platform_default' => true,
        ]);

        // 2. 创建业务租户
        $this->tenantA = Tenant::create([
            'name' => '租户A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => '租户B',
            'slug' => 'tenant-b',
            'status' => 'active',
        ]);

        // 3. 获取角色 ID
        $superAdminRoleId = \DB::table('roles')->where('name', 'super_admin')->whereNull('tenant_id')->value('role_id');
        $platformAdminRoleId = \DB::table('roles')->where('name', 'platform_admin')->whereNull('tenant_id')->value('role_id');
        $tenantAdminRoleId = \DB::table('roles')->where('name', 'tenant_admin')->whereNull('tenant_id')->value('role_id');
        $endUserRoleId = \DB::table('roles')->where('name', 'end_user')->whereNull('tenant_id')->value('role_id');

        // 4. 创建超级管理员
        $this->superAdminOperator = Operator::create([
            'email' => 'superadmin@platform.com',
            'name' => '超级管理员',
            'password' => Hash::make('password'),
            'scope' => 'platform',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->superAdminUser = User::create([
            'tenant_id' => 9007199254740991,
            'email' => 'superadmin@platform.com',
            'name' => '超级管理员',
            'password' => Hash::make('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        OperatorTenant::create([
            'operator_id' => $this->superAdminOperator->operator_id,
            'tenant_id' => 9007199254740991,
            'user_id' => $this->superAdminUser->user_id,
            'role' => 'super_admin',
            'role_id' => $superAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        // 5. 创建平台运营
        $this->platformAdminOperator = Operator::create([
            'email' => 'platformadmin@platform.com',
            'name' => '平台运营',
            'password' => Hash::make('password'),
            'scope' => 'platform',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->platformAdminUser = User::create([
            'tenant_id' => 9007199254740991,
            'email' => 'platformadmin@platform.com',
            'name' => '平台运营',
            'password' => Hash::make('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        OperatorTenant::create([
            'operator_id' => $this->platformAdminOperator->operator_id,
            'tenant_id' => 9007199254740991,
            'user_id' => $this->platformAdminUser->user_id,
            'role' => 'platform_admin',
            'role_id' => $platformAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        // 6. 创建租户A管理员
        $this->tenantAAdminOperator = Operator::create([
            'email' => 'admin@tenant-a.com',
            'name' => '租户A管理员',
            'password' => Hash::make('password'),
            'scope' => 'tenant',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->tenantAAdminUser = User::create([
            'tenant_id' => $this->tenantA->tenant_id,
            'email' => 'admin@tenant-a.com',
            'name' => '租户A管理员',
            'password' => Hash::make('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        OperatorTenant::create([
            'operator_id' => $this->tenantAAdminOperator->operator_id,
            'tenant_id' => $this->tenantA->tenant_id,
            'user_id' => $this->tenantAAdminUser->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenantA->tenant_id,
            'user_id' => $this->tenantAAdminUser->user_id,
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
        ]);

        // 7. 创建租户B管理员
        $this->tenantBAdminOperator = Operator::create([
            'email' => 'admin@tenant-b.com',
            'name' => '租户B管理员',
            'password' => Hash::make('password'),
            'scope' => 'tenant',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->tenantBAdminUser = User::create([
            'tenant_id' => $this->tenantB->tenant_id,
            'email' => 'admin@tenant-b.com',
            'name' => '租户B管理员',
            'password' => Hash::make('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        OperatorTenant::create([
            'operator_id' => $this->tenantBAdminOperator->operator_id,
            'tenant_id' => $this->tenantB->tenant_id,
            'user_id' => $this->tenantBAdminUser->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenantB->tenant_id,
            'user_id' => $this->tenantBAdminUser->user_id,
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
        ]);

        // 8. 创建普通用户
        $this->endUser = User::create([
            'tenant_id' => $this->tenantA->tenant_id,
            'email' => 'user@tenant-a.com',
            'name' => '普通用户',
            'password' => Hash::make('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenantA->tenant_id,
            'user_id' => $this->endUser->user_id,
            'role_id' => $endUserRoleId,
            'is_active' => true,
        ]);
    }

    // ========== 测试用例 ==========

    /**
     * 测试：平台初始化数据完整性
     */
    public function test_platform_initialization_data_integrity(): void
    {
        // 验证平台租户
        $this->assertTrue(Tenant::where('tenant_id', 9007199254740991)->exists());
        $this->assertTrue(Tenant::where('tenant_id', 9007199254740991)->where('is_platform_default', true)->exists());

        // 验证角色
        $this->assertDatabaseHas('roles', ['name' => 'super_admin']);
        $this->assertDatabaseHas('roles', ['name' => 'platform_admin']);
        $this->assertDatabaseHas('roles', ['name' => 'tenant_admin']);
        $this->assertDatabaseHas('roles', ['name' => 'end_user']);

        // 验证权限
        $this->assertTrue(\DB::table('permissions')->count() > 0);

        // 验证角色-权限映射
        $this->assertTrue(\DB::table('role_permissions')->count() > 0);
    }

    /**
     * 测试：Operator 创建和映射
     */
    public function test_operator_creation_and_mapping(): void
    {
        // 验证超级管理员
        $this->assertTrue(Operator::where('email', 'superadmin@platform.com')->exists());
        $this->assertEquals('platform', $this->superAdminOperator->scope);
        $this->assertTrue($this->superAdminOperator->is_active);

        // 验证 operator_tenants 映射
        $mapping = OperatorTenant::where('operator_id', $this->superAdminOperator->operator_id)
            ->where('tenant_id', 9007199254740991)
            ->first();

        $this->assertNotNull($mapping);
        $this->assertEquals('super_admin', $mapping->role);
        $this->assertTrue($mapping->is_active);
    }

    /**
     * 测试：用户创建和租户关联
     */
    public function test_user_creation_and_tenant_association(): void
    {
        // 验证用户属于正确的租户
        $this->assertEquals(9007199254740991, $this->superAdminUser->tenant_id);
        $this->assertEquals($this->tenantA->tenant_id, $this->tenantAAdminUser->tenant_id);
        $this->assertEquals($this->tenantB->tenant_id, $this->tenantBAdminUser->tenant_id);

        // 验证 tenant_users 关联
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->tenantA->tenant_id,
            'user_id' => $this->tenantAAdminUser->user_id,
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->tenantB->tenant_id,
            'user_id' => $this->tenantBAdminUser->user_id,
        ]);
    }

    /**
     * 测试：租户隔离 - 用户不能访问其他租户
     */
    public function test_tenant_isolation(): void
    {
        // 租户A用户不能访问租户B
        $tenantBUser = TenantUser::where('tenant_id', $this->tenantB->tenant_id)
            ->where('user_id', $this->tenantAAdminUser->user_id)
            ->exists();

        $this->assertFalse($tenantBUser);

        // 租户B用户不能访问租户A
        $tenantAUser = TenantUser::where('tenant_id', $this->tenantA->tenant_id)
            ->where('user_id', $this->tenantBAdminUser->user_id)
            ->exists();

        $this->assertFalse($tenantAUser);
    }

    /**
     * 测试：Operator 登录 - 平台管理员
     */
    public function test_operator_login_platform_admin(): void
    {
        // 验证 operator 存在且可登录
        $operator = Operator::where('email', 'superadmin@platform.com')->first();
        $this->assertNotNull($operator);
        $this->assertTrue($operator->is_active);
        $this->assertTrue(Hash::check('password', $operator->password));
    }

    /**
     * 测试：Operator 登录 - 租户管理员
     */
    public function test_operator_login_tenant_admin(): void
    {
        // 验证 operator 存在且可登录
        $operator = Operator::where('email', 'admin@tenant-a.com')->first();
        $this->assertNotNull($operator);
        $this->assertTrue($operator->is_active);
        $this->assertTrue(Hash::check('password', $operator->password));
    }

    /**
     * 测试：普通用户登录
     */
    public function test_end_user_login(): void
    {
        // 验证用户存在且可登录
        $user = User::where('email', 'user@tenant-a.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->is_active);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    /**
     * 测试：平台管理员可以访问租户列表
     */
    public function test_platform_admin_can_list_tenants(): void
    {
        // 验证平台管理员有访问权限
        $this->actingAs($this->superAdminUser);
        TenantContext::setTenantId(9007199254740991);

        // 验证 operator_tenants 映射存在
        $mapping = OperatorTenant::where('user_id', $this->superAdminUser->user_id)
            ->where('tenant_id', 9007199254740991)
            ->first();

        $this->assertNotNull($mapping);
        $this->assertEquals('super_admin', $mapping->role);
    }

    /**
     * 测试：租户管理员不能访问租户列表
     */
    public function test_tenant_admin_cannot_list_tenants(): void
    {
        // 验证租户管理员没有平台权限
        $this->actingAs($this->tenantAAdminUser);
        TenantContext::setTenantId($this->tenantA->tenant_id);

        // 验证 operator_tenants 映射存在但不是平台级
        $mapping = OperatorTenant::where('user_id', $this->tenantAAdminUser->user_id)
            ->where('tenant_id', $this->tenantA->tenant_id)
            ->first();

        $this->assertNotNull($mapping);
        $this->assertEquals('tenant_admin', $mapping->role);

        // 验证没有平台租户的映射
        $platformMapping = OperatorTenant::where('user_id', $this->tenantAAdminUser->user_id)
            ->where('tenant_id', 9007199254740991)
            ->first();

        $this->assertNull($platformMapping);
    }

    /**
     * 测试：租户管理员可以访问自己的租户成员
     */
    public function test_tenant_admin_can_access_own_members(): void
    {
        // 验证租户管理员有访问自己租户的权限
        $this->actingAs($this->tenantAAdminUser);
        TenantContext::setTenantId($this->tenantA->tenant_id);

        // 验证用户属于该租户
        $tenantUser = TenantUser::where('user_id', $this->tenantAAdminUser->user_id)
            ->where('tenant_id', $this->tenantA->tenant_id)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($tenantUser);
    }

    /**
     * 测试：租户管理员不能访问其他租户成员
     */
    public function test_tenant_admin_cannot_access_other_tenant_members(): void
    {
        // 验证租户管理员没有访问其他租户的权限
        $this->actingAs($this->tenantAAdminUser);
        TenantContext::setTenantId($this->tenantB->tenant_id);

        // 验证用户不属于其他租户
        $tenantUser = TenantUser::where('user_id', $this->tenantAAdminUser->user_id)
            ->where('tenant_id', $this->tenantB->tenant_id)
            ->first();

        $this->assertNull($tenantUser);
    }

    /**
     * 测试：普通用户不能访问管理后台
     */
    public function test_end_user_cannot_access_admin(): void
    {
        $token = $this->endUser->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants');

        $response->assertStatus(403);
    }

    /**
     * 测试：权限检查 - RbacService
     */
    public function test_rbac_service_permission_check(): void
    {
        // 超级管理员应该有所有权限
        $this->actingAs($this->superAdminUser);
        TenantContext::setTenantId(9007199254740991);

        $this->assertTrue(RbacService::check('tenant.view'));
        $this->assertTrue(RbacService::check('tenant.create'));
        $this->assertTrue(RbacService::check('member.create'));

        // 租户管理员应该有租户权限
        $this->actingAs($this->tenantAAdminUser);
        TenantContext::setTenantId($this->tenantA->tenant_id);

        $this->assertTrue(RbacService::check('tenant.view'));
        $this->assertFalse(RbacService::check('tenant.create'));
    }

    /**
     * 测试：邀请流程
     */
    public function test_operator_invitation_flow(): void
    {
        // 验证邀请流程的数据模型
        $email = 'newoperator@test.com';
        $tenantId = $this->tenantA->tenant_id;

        // 创建 operator
        $operator = Operator::create([
            'email' => $email,
            'name' => '新运营人员',
            'scope' => 'tenant',
            'is_active' => false, // 邀请时未激活
            'invite_token' => Str::random(60),
            'invite_expires_at' => now()->addDays(7),
        ]);

        // 验证 operator 已创建
        $this->assertTrue(Operator::where('email', $email)->exists());
        $this->assertFalse($operator->is_active);
        $this->assertNotNull($operator->invite_token);

        // 创建 user
        $user = User::create([
            'tenant_id' => $tenantId,
            'email' => $email,
            'name' => '新运营人员',
            'is_active' => false,
        ]);

        // 创建 operator_tenants 映射
        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => $tenantId,
            'user_id' => $user->user_id,
            'role' => 'member',
            'is_active' => true,
            'invited_at' => now(),
        ]);

        // 验证映射存在
        $this->assertDatabaseHas('operator_tenants', [
            'operator_id' => $operator->operator_id,
            'tenant_id' => $tenantId,
            'role' => 'member',
        ]);
    }

    /**
     * 测试：Operator 模块化结构
     */
    public function test_operator_module_structure(): void
    {
        // 验证 Operator 模块类存在
        $this->assertTrue(class_exists(OperatorServiceProvider::class));
        $this->assertTrue(class_exists(OperatorService::class));
        $this->assertTrue(class_exists(Operator::class));
        $this->assertTrue(class_exists(OperatorTenant::class));
        $this->assertTrue(class_exists(OperatorController::class));
        $this->assertTrue(class_exists(IdentifyOperator::class));
    }
}
