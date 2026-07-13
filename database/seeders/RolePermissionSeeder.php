<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 角色-权限映射 Seeder
 *
 * 为所有系统角色分配权限。已由迁移分配的映射不会重复插入。
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 所有权限 ID
        $allPermIds = DB::table('permissions')->pluck('permission_id', 'name');

        // 定义各角色的权限
        $rolePermissions = [
            // 超级管理员：全部权限
            'super_admin' => $allPermIds->values()->all(),

            // 平台管理员：全部权限
            'platform_admin' => $allPermIds->values()->all(),

            // 平台支持：查看类 + 成员 + RBAC
            'platform_support' => $allPermIds->filter(fn ($_, $name) => str_contains($name, '.view') || in_array($name, [
                'member.create', 'member.update', 'member.delete',
                'rbac.manage',
            ]))->values()->all(),

            // 平台用户：基础平台操作
            'platform_user' => $allPermIds->filter(fn ($_, $name) => in_array($name, [
                'tenant.view', 'member.view', 'credit.view', 'setting.view', 'setting.update',
                'file.upload', 'conversation.view', 'conversation.create',
                'workflow.view', 'workflow.create', 'workflow.execute',
            ]))->values()->all(),

            // 租户管理员：除 tenant CRUD 外的全部权限
            'tenant_admin' => $allPermIds->filter(fn ($_, $name) => ! in_array($name, [
                'tenant.create', 'tenant.delete', 'tenant.suspend',
            ]))->values()->all(),

            // 普通用户：查看 + 基础操作
            'end_user' => $allPermIds->filter(fn ($_, $name) => str_contains($name, '.view') || in_array($name, [
                'file.upload', 'lottery.draw', 'voting.vote', 'coupon.redeem',
                'conversation.create',
            ]))->values()->all(),

            // 成员：与普通用户相同
            'member' => $allPermIds->filter(fn ($_, $name) => str_contains($name, '.view') || in_array($name, [
                'file.upload', 'lottery.draw', 'voting.vote', 'coupon.redeem',
                'conversation.create',
            ]))->values()->all(),

            // 观察者：仅查看权限
            'viewer' => $allPermIds->filter(fn ($_, $name) => str_contains($name, '.view'))->values()->all(),

            // 订单管理员：支付 + 订阅 + 积分 + 报表
            'order_manager' => $allPermIds->filter(fn ($_, $name) => in_array($name, [
                'payment.view', 'payment.create', 'payment.refund',
                'subscription.manage',
                'credit.view', 'credit.recharge', 'credit.adjust',
                'report.view', 'report.export',
            ]))->values()->all(),

            // 销售：支付 + 订阅 + 成员 + 优惠券 + 报表
            'sales' => $allPermIds->filter(fn ($_, $name) => in_array($name, [
                'payment.view', 'payment.create',
                'subscription.manage',
                'member.view', 'member.create', 'member.update',
                'coupon.view', 'coupon.create', 'coupon.update',
                'report.view', 'report.export',
            ]))->values()->all(),

            // 市场运营：营销模块 + 优惠券 + 抽奖 + 投票 + 表单 + 报表
            'marketing' => $allPermIds->filter(fn ($_, $name) => in_array($name, [
                'coupon.view', 'coupon.create', 'coupon.update', 'coupon.delete',
                'lottery.view', 'lottery.create', 'lottery.update', 'lottery.delete',
                'voting.view', 'voting.create', 'voting.update', 'voting.delete',
                'form.view', 'form.create', 'form.update', 'form.delete', 'form.export',
                'notification.view', 'notification.send',
                'report.view', 'report.export',
            ]))->values()->all(),

            // 客服专员：查看 + 成员 + 通知
            'support_agent' => $allPermIds->filter(fn ($_, $name) => in_array($name, [
                'tenant.view', 'member.view', 'member.update',
                'credit.view',
                'conversation.view',
                'notification.view', 'notification.send',
                'audit.view',
            ]))->values()->all(),

            // 财务人员：支付 + 积分 + 订阅 + 报表
            'finance' => $allPermIds->filter(fn ($_, $name) => in_array($name, [
                'payment.view', 'payment.create', 'payment.refund',
                'credit.view', 'credit.recharge', 'credit.adjust',
                'subscription.manage',
                'report.view', 'report.export',
                'audit.view',
            ]))->values()->all(),

            // 分析师：查看 + 报表 + 审计
            'analyst' => $allPermIds->filter(fn ($_, $name) => str_contains($name, '.view') || in_array($name, [
                'report.view', 'report.export',
            ]))->values()->all(),
        ];

        $totalInserted = 0;

        foreach ($rolePermissions as $roleName => $permIds) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->whereNull('tenant_id')
                ->value('role_id');

            if (! $roleId) {
                $this->command->warn("角色 {$roleName} 不存在，跳过");

                continue;
            }

            foreach ($permIds as $permId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
                $totalInserted++;
            }
        }

        $this->command->info("角色-权限映射已创建: {$totalInserted} 条");
    }
}
