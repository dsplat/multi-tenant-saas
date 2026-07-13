<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 系统角色 Seeder
 *
 * 补充 RBAC 迁移已创建的 4 个基础角色（super_admin, platform_user, tenant_admin, end_user），
 * 新增 9 个系统级角色。仅创建不存在的角色，不会覆盖已有数据。
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        $roles = [
            ['name' => 'platform_admin', 'display_name' => '平台管理员', 'description' => '平台运营管理角色'],
            ['name' => 'platform_support', 'display_name' => '平台支持', 'description' => '平台客服支持角色'],
            ['name' => 'member', 'display_name' => '成员', 'description' => '基础成员角色'],
            ['name' => 'viewer', 'display_name' => '观察者', 'description' => '只读访问角色'],
            ['name' => 'order_manager', 'display_name' => '订单管理员', 'description' => '订单和支付管理角色'],
            ['name' => 'sales', 'display_name' => '销售人员', 'description' => '销售业务角色'],
            ['name' => 'marketing', 'display_name' => '市场运营', 'description' => '市场活动运营角色'],
            ['name' => 'support_agent', 'display_name' => '客服专员', 'description' => '客户服务角色'],
            ['name' => 'finance', 'display_name' => '财务人员', 'description' => '财务管理角色'],
            ['name' => 'analyst', 'display_name' => '分析师', 'description' => '数据分析角色'],
        ];

        $created = 0;

        foreach ($roles as $role) {
            $exists = DB::table('roles')
                ->where('tenant_id', null)
                ->where('name', $role['name'])
                ->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'role_id' => $idGenerator->generate(),
                    'tenant_id' => null,
                    'name' => $role['name'],
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        $this->command->info("系统角色已创建: {$created} 个新角色");
    }
}
