<?php

use Illuminate\Database\Migrations\Migration;
use MultiTenantSaas\Contracts\IdGeneratorContract;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $idGenerator = app(IdGeneratorContract::class);

        $permissions = [
            // 抽奖模块
            ['name' => 'lottery.view', 'display_name' => '查看抽奖', 'group' => 'lottery', 'description' => '查看抽奖活动和记录'],
            ['name' => 'lottery.create', 'display_name' => '创建抽奖', 'group' => 'lottery', 'description' => '创建抽奖活动'],
            ['name' => 'lottery.update', 'display_name' => '更新抽奖', 'group' => 'lottery', 'description' => '更新抽奖活动'],
            ['name' => 'lottery.delete', 'display_name' => '删除抽奖', 'group' => 'lottery', 'description' => '删除抽奖活动'],
            ['name' => 'lottery.draw', 'display_name' => '执行抽奖', 'group' => 'lottery', 'description' => '参与抽奖'],

            // 投票模块
            ['name' => 'voting.view', 'display_name' => '查看投票', 'group' => 'voting', 'description' => '查看投票活动和结果'],
            ['name' => 'voting.create', 'display_name' => '创建投票', 'group' => 'voting', 'description' => '创建投票活动'],
            ['name' => 'voting.update', 'display_name' => '更新投票', 'group' => 'voting', 'description' => '更新投票活动'],
            ['name' => 'voting.delete', 'display_name' => '删除投票', 'group' => 'voting', 'description' => '删除投票活动'],
            ['name' => 'voting.vote', 'display_name' => '参与投票', 'group' => 'voting', 'description' => '参与投票'],

            // 表单模块
            ['name' => 'form.view', 'display_name' => '查看表单', 'group' => 'form', 'description' => '查看表单和提交数据'],
            ['name' => 'form.create', 'display_name' => '创建表单', 'group' => 'form', 'description' => '创建表单'],
            ['name' => 'form.update', 'display_name' => '更新表单', 'group' => 'form', 'description' => '更新表单'],
            ['name' => 'form.delete', 'display_name' => '删除表单', 'group' => 'form', 'description' => '删除表单'],
            ['name' => 'form.export', 'display_name' => '导出数据', 'group' => 'form', 'description' => '导出表单提交数据'],

            // 优惠券模块
            ['name' => 'coupon.view', 'display_name' => '查看优惠券', 'group' => 'coupon', 'description' => '查看优惠券和使用记录'],
            ['name' => 'coupon.create', 'display_name' => '创建优惠券', 'group' => 'coupon', 'description' => '创建优惠券'],
            ['name' => 'coupon.update', 'display_name' => '更新优惠券', 'group' => 'coupon', 'description' => '更新优惠券'],
            ['name' => 'coupon.delete', 'display_name' => '删除优惠券', 'group' => 'coupon', 'description' => '删除优惠券'],
            ['name' => 'coupon.redeem', 'display_name' => '核销优惠券', 'group' => 'coupon', 'description' => '核销优惠券'],
        ];

        foreach ($permissions as &$p) {
            $p['permission_id'] = $idGenerator->generate();
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
        }

        DB::table('permissions')->insert($permissions);

        // 为 tenant_admin 分配新权限
        $newPermIds = DB::table('permissions')
            ->whereIn('name', array_column($permissions, 'name'))
            ->pluck('permission_id');

        $adminRoleId = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        if ($adminRoleId) {
            $insert = $newPermIds->map(fn ($pid) => [
                'role_id' => $adminRoleId,
                'permission_id' => $pid,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('role_permissions')->insert($insert);
        }

        // super_admin 获得所有权限（已在原 seed 中处理，这里只需确保新权限被分配）
        $superRoleId = DB::table('roles')
            ->where('name', 'super_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        if ($superRoleId) {
            $insertSuper = $newPermIds->map(fn ($pid) => [
                'role_id' => $superRoleId,
                'permission_id' => $pid,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('role_permissions')->insert($insertSuper);
        }

        // end_user 只给查看和参与权限
        $userPermNames = [
            'lottery.view', 'lottery.draw',
            'voting.view', 'voting.vote',
            'form.view',
            'coupon.view', 'coupon.redeem',
        ];

        $userPermIds = DB::table('permissions')
            ->whereIn('name', $userPermNames)
            ->pluck('permission_id');

        $userRoleId = DB::table('roles')
            ->where('name', 'end_user')
            ->whereNull('tenant_id')
            ->value('role_id');

        if ($userRoleId) {
            $insertUser = $userPermIds->map(fn ($pid) => [
                'role_id' => $userRoleId,
                'permission_id' => $pid,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('role_permissions')->insert($insertUser);
        }
    }

    public function down(): void
    {
        $permNames = [
            'lottery.view', 'lottery.create', 'lottery.update', 'lottery.delete', 'lottery.draw',
            'voting.view', 'voting.create', 'voting.update', 'voting.delete', 'voting.vote',
            'form.view', 'form.create', 'form.update', 'form.delete', 'form.export',
            'coupon.view', 'coupon.create', 'coupon.update', 'coupon.delete', 'coupon.redeem',
        ];

        $permIds = DB::table('permissions')
            ->whereIn('name', $permNames)
            ->pluck('permission_id');

        DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('name', $permNames)->delete();
    }
};
