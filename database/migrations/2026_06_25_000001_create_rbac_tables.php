<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

return new class extends Migration
{
    public function up(): void
    {
        // 权限表（全局，系统级）
        Schema::create('permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id')->primary()->comment('权限ID（全局ID）');
            $table->string('name', 100)->unique()->comment('权限标识，如 tenant.users.create');
            $table->string('display_name', 200);
            $table->string('group', 50)->default('general')->comment('权限分组');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 角色表（支持系统级 + 租户级）
        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->primary()->comment('角色ID（全局ID）');
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index()->comment('null=系统级角色');
            $table->string('name', 50)->comment('角色标识');
            $table->string('display_name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)->comment('系统内置角色不可删除');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // 角色-权限关联
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();

            $table->foreign('role_id')->references('role_id')->on('roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('permission_id')->on('permissions')->onDelete('cascade');
            $table->unique(['role_id', 'permission_id']);
        });

        // tenant_users 增加 role_id 列（向后兼容，保留 role 字符串列）
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('role');
            $table->foreign('role_id')->references('role_id')->on('roles')->onDelete('set null');
        });

        // 插入系统内置角色
        $this->seedSystemRoles();
        // 插入默认权限
        $this->seedPermissions();
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    private function seedSystemRoles(): void
    {
        $now = now();
        $idGenerator = app(IdGeneratorContract::class);
        DB::table('roles')->insert([
            ['role_id' => $idGenerator->generate(), 'tenant_id' => null, 'name' => 'super_admin', 'display_name' => '超级管理员', 'description' => '系统级管理角色', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['role_id' => $idGenerator->generate(), 'tenant_id' => null, 'name' => 'platform_user', 'display_name' => '平台用户', 'description' => '平台运营角色', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['role_id' => $idGenerator->generate(), 'tenant_id' => null, 'name' => 'tenant_admin', 'display_name' => '租户管理员', 'description' => '租户管理角色', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['role_id' => $idGenerator->generate(), 'tenant_id' => null, 'name' => 'end_user', 'display_name' => '普通用户', 'description' => '终端用户角色', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    private function seedPermissions(): void
    {
        $now = now();
        $idGenerator = app(IdGeneratorContract::class);
        $permissions = [
            // 租户管理
            ['name' => 'tenant.create', 'display_name' => '创建租户', 'group' => 'tenant', 'description' => '创建新租户'],
            ['name' => 'tenant.update', 'display_name' => '更新租户', 'group' => 'tenant', 'description' => '更新租户信息'],
            ['name' => 'tenant.delete', 'display_name' => '删除租户', 'group' => 'tenant', 'description' => '删除租户'],
            ['name' => 'tenant.suspend', 'display_name' => '暂停租户', 'group' => 'tenant', 'description' => '暂停租户'],
            ['name' => 'tenant.activate', 'display_name' => '恢复租户', 'group' => 'tenant', 'description' => '恢复已暂停的租户'],
            ['name' => 'tenant.view', 'display_name' => '查看租户', 'group' => 'tenant', 'description' => '查看租户详情'],
            // 成员管理
            ['name' => 'member.create', 'display_name' => '添加成员', 'group' => 'member', 'description' => '向租户添加成员'],
            ['name' => 'member.update', 'display_name' => '更新成员', 'group' => 'member', 'description' => '更新成员信息'],
            ['name' => 'member.delete', 'display_name' => '移除成员', 'group' => 'member', 'description' => '从租户移除成员'],
            ['name' => 'member.view', 'display_name' => '查看成员', 'group' => 'member', 'description' => '查看成员列表'],
            // 积分管理
            ['name' => 'credit.view', 'display_name' => '查看积分', 'group' => 'credit', 'description' => '查看积分账户'],
            ['name' => 'credit.recharge', 'display_name' => '积分充值', 'group' => 'credit', 'description' => '充值积分'],
            ['name' => 'credit.adjust', 'display_name' => '积分调整', 'group' => 'credit', 'description' => '手动调整积分'],
            // 配置管理
            ['name' => 'setting.view', 'display_name' => '查看配置', 'group' => 'setting', 'description' => '查看租户配置'],
            ['name' => 'setting.update', 'display_name' => '更新配置', 'group' => 'setting', 'description' => '更新租户配置'],
            // 支付管理
            ['name' => 'payment.view', 'display_name' => '查看支付', 'group' => 'payment', 'description' => '查看支付订单'],
            ['name' => 'payment.create', 'display_name' => '创建支付', 'group' => 'payment', 'description' => '创建支付订单'],
            ['name' => 'payment.refund', 'display_name' => '发起退款', 'group' => 'payment', 'description' => '发起退款请求'],
            // 域名/SSL
            ['name' => 'domain.manage', 'display_name' => '域名管理', 'group' => 'domain', 'description' => '管理域名配置'],
            ['name' => 'ssl.manage', 'display_name' => 'SSL管理', 'group' => 'ssl', 'description' => '管理SSL证书'],
            // 审计
            ['name' => 'audit.view', 'display_name' => '查看审计', 'group' => 'audit', 'description' => '查看审计日志'],
            // RBAC
            ['name' => 'rbac.manage', 'display_name' => '权限管理', 'group' => 'rbac', 'description' => '管理角色和权限'],
            // 文件
            ['name' => 'file.upload', 'display_name' => '上传文件', 'group' => 'file', 'description' => '上传文件'],
            ['name' => 'file.delete', 'display_name' => '删除文件', 'group' => 'file', 'description' => '删除文件'],
            // 订阅
            ['name' => 'subscription.manage', 'display_name' => '订阅管理', 'group' => 'subscription', 'description' => '管理订阅计划'],
        ];

        foreach ($permissions as &$p) {
            $p['permission_id'] = $idGenerator->generate();
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
        }
        DB::table('permissions')->insert($permissions);

        // 为 tenant_admin 分配除 tenant.create/delete/suspend 外的所有权限
        $adminPerms = DB::table('permissions')
            ->whereNotIn('name', ['tenant.create', 'tenant.delete', 'tenant.suspend'])
            ->pluck('permission_id');
        $adminRoleId = DB::table('roles')->where('name', 'tenant_admin')->whereNull('tenant_id')->value('role_id');

        $insert = $adminPerms->map(fn ($pid) => [
            'role_id' => $adminRoleId,
            'permission_id' => $pid,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        DB::table('role_permissions')->insert($insert);

        // end_user 只给查看权限
        $userPerms = DB::table('permissions')
            ->whereIn('name', ['tenant.view', 'member.view', 'credit.view', 'setting.view', 'payment.view', 'audit.view', 'file.upload'])
            ->pluck('permission_id');
        $userRoleId = DB::table('roles')->where('name', 'end_user')->whereNull('tenant_id')->value('role_id');

        $insert2 = $userPerms->map(fn ($pid) => [
            'role_id' => $userRoleId,
            'permission_id' => $pid,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        DB::table('role_permissions')->insert($insert2);

        // super_admin 获得所有权限
        $allPerms = DB::table('permissions')->pluck('permission_id');
        $superRoleId = DB::table('roles')->where('name', 'super_admin')->whereNull('tenant_id')->value('role_id');

        $insert3 = $allPerms->map(fn ($pid) => [
            'role_id' => $superRoleId,
            'permission_id' => $pid,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        DB::table('role_permissions')->insert($insert3);
    }
};
