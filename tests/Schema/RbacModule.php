<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC 权限模块
 * 表: permissions, roles, role_permissions
 * 自动 seed 系统角色、权限及映射（与 migration 保持一致）
 */
class RbacModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id')->primary();
            $table->string('name', 100)->unique();
            $table->string('display_name', 200);
            $table->string('group', 50)->default('general');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->string('name', 50);
            $table->string('display_name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        $this->seedData();
    }

    public function getTableNames(): array
    {
        return ['permissions', 'roles', 'role_permissions'];
    }

    public function seedData(): void
    {
        // 幂等：如果已有数据则跳过
        if (DB::table('roles')->count() > 0) {
            return;
        }

        $now = now();

        // 系统角色
        $roles = [
            ['role_id' => 1, 'name' => 'super_admin', 'display_name' => '超级管理员'],
            ['role_id' => 2, 'name' => 'platform_user', 'display_name' => '平台用户'],
            ['role_id' => 3, 'name' => 'tenant_admin', 'display_name' => '租户管理员'],
            ['role_id' => 4, 'name' => 'end_user', 'display_name' => '普通用户'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['role_id' => $role['role_id']],
                array_merge($role, [
                    'tenant_id' => null,
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        // 权限
        $permissions = [
            ['permission_id' => 1, 'name' => 'tenant.create', 'display_name' => '创建租户', 'group' => 'tenant'],
            ['permission_id' => 2, 'name' => 'tenant.update', 'display_name' => '更新租户', 'group' => 'tenant'],
            ['permission_id' => 3, 'name' => 'tenant.delete', 'display_name' => '删除租户', 'group' => 'tenant'],
            ['permission_id' => 4, 'name' => 'tenant.suspend', 'display_name' => '暂停租户', 'group' => 'tenant'],
            ['permission_id' => 5, 'name' => 'tenant.activate', 'display_name' => '恢复租户', 'group' => 'tenant'],
            ['permission_id' => 6, 'name' => 'tenant.view', 'display_name' => '查看租户', 'group' => 'tenant'],
            ['permission_id' => 7, 'name' => 'member.create', 'display_name' => '添加成员', 'group' => 'member'],
            ['permission_id' => 8, 'name' => 'member.update', 'display_name' => '更新成员', 'group' => 'member'],
            ['permission_id' => 9, 'name' => 'member.delete', 'display_name' => '移除成员', 'group' => 'member'],
            ['permission_id' => 10, 'name' => 'member.view', 'display_name' => '查看成员', 'group' => 'member'],
            ['permission_id' => 11, 'name' => 'credit.view', 'display_name' => '查看积分', 'group' => 'credit'],
            ['permission_id' => 12, 'name' => 'credit.recharge', 'display_name' => '积分充值', 'group' => 'credit'],
            ['permission_id' => 13, 'name' => 'credit.adjust', 'display_name' => '积分调整', 'group' => 'credit'],
            ['permission_id' => 14, 'name' => 'setting.view', 'display_name' => '查看配置', 'group' => 'setting'],
            ['permission_id' => 15, 'name' => 'setting.update', 'display_name' => '更新配置', 'group' => 'setting'],
            ['permission_id' => 16, 'name' => 'payment.view', 'display_name' => '查看支付', 'group' => 'payment'],
            ['permission_id' => 17, 'name' => 'payment.create', 'display_name' => '创建支付', 'group' => 'payment'],
            ['permission_id' => 18, 'name' => 'payment.refund', 'display_name' => '发起退款', 'group' => 'payment'],
            ['permission_id' => 19, 'name' => 'domain.manage', 'display_name' => '域名管理', 'group' => 'domain'],
            ['permission_id' => 20, 'name' => 'ssl.manage', 'display_name' => 'SSL管理', 'group' => 'ssl'],
            ['permission_id' => 21, 'name' => 'audit.view', 'display_name' => '查看审计', 'group' => 'audit'],
            ['permission_id' => 22, 'name' => 'rbac.manage', 'display_name' => '权限管理', 'group' => 'rbac'],
            ['permission_id' => 23, 'name' => 'file.upload', 'display_name' => '上传文件', 'group' => 'file'],
            ['permission_id' => 24, 'name' => 'file.delete', 'display_name' => '删除文件', 'group' => 'file'],
            ['permission_id' => 25, 'name' => 'subscription.manage', 'display_name' => '订阅管理', 'group' => 'subscription'],
            ['permission_id' => 26, 'name' => 'coupon.view', 'display_name' => '查看优惠券', 'group' => 'coupon'],
            ['permission_id' => 27, 'name' => 'coupon.create', 'display_name' => '创建优惠券', 'group' => 'coupon'],
            ['permission_id' => 28, 'name' => 'coupon.update', 'display_name' => '更新优惠券', 'group' => 'coupon'],
            ['permission_id' => 29, 'name' => 'coupon.delete', 'display_name' => '删除优惠券', 'group' => 'coupon'],
            ['permission_id' => 30, 'name' => 'coupon.redeem', 'display_name' => '核销优惠券', 'group' => 'coupon'],
            ['permission_id' => 31, 'name' => 'coupon.validate', 'display_name' => '验证优惠券', 'group' => 'coupon'],
            ['permission_id' => 32, 'name' => 'setting.delete', 'display_name' => '删除配置', 'group' => 'setting'],
            // 投票模块
            ['permission_id' => 33, 'name' => 'voting.view', 'display_name' => '查看投票', 'group' => 'voting'],
            ['permission_id' => 34, 'name' => 'voting.create', 'display_name' => '创建投票', 'group' => 'voting'],
            ['permission_id' => 35, 'name' => 'voting.update', 'display_name' => '更新投票', 'group' => 'voting'],
            ['permission_id' => 36, 'name' => 'voting.delete', 'display_name' => '删除投票', 'group' => 'voting'],
            ['permission_id' => 37, 'name' => 'voting.vote', 'display_name' => '参与投票', 'group' => 'voting'],
            // 抽奖模块
            ['permission_id' => 38, 'name' => 'lottery.view', 'display_name' => '查看抽奖', 'group' => 'lottery'],
            ['permission_id' => 39, 'name' => 'lottery.create', 'display_name' => '创建抽奖', 'group' => 'lottery'],
            ['permission_id' => 40, 'name' => 'lottery.update', 'display_name' => '更新抽奖', 'group' => 'lottery'],
            ['permission_id' => 41, 'name' => 'lottery.delete', 'display_name' => '删除抽奖', 'group' => 'lottery'],
            ['permission_id' => 42, 'name' => 'lottery.draw', 'display_name' => '执行抽奖', 'group' => 'lottery'],
            // 表单模块
            ['permission_id' => 43, 'name' => 'form.view', 'display_name' => '查看表单', 'group' => 'form'],
            ['permission_id' => 44, 'name' => 'form.create', 'display_name' => '创建表单', 'group' => 'form'],
            ['permission_id' => 45, 'name' => 'form.update', 'display_name' => '更新表单', 'group' => 'form'],
            ['permission_id' => 46, 'name' => 'form.delete', 'display_name' => '删除表单', 'group' => 'form'],
            ['permission_id' => 47, 'name' => 'form.export', 'display_name' => '导出表单', 'group' => 'form'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['permission_id' => $perm['permission_id']],
                array_merge($perm, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        // tenant_admin: 除 tenant.create/delete/suspend 外的所有权限
        $adminExclude = ['tenant.create', 'tenant.delete', 'tenant.suspend'];
        $adminPerms = DB::table('permissions')->whereNotIn('name', $adminExclude)->pluck('permission_id');
        foreach ($adminPerms as $pid) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => 3, 'permission_id' => $pid],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // end_user: 仅查看权限
        $userPermNames = ['tenant.view', 'member.view', 'credit.view', 'setting.view', 'payment.view', 'audit.view', 'file.upload'];
        $userPerms = DB::table('permissions')->whereIn('name', $userPermNames)->pluck('permission_id');
        foreach ($userPerms as $pid) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => 4, 'permission_id' => $pid],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // super_admin: 所有权限
        $allPerms = DB::table('permissions')->pluck('permission_id');
        foreach ($allPerms as $pid) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => 1, 'permission_id' => $pid],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}
