<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 平台初始化 Seeder
 *
 * 整合所有平台初始化 seeder，按正确顺序执行：
 * 1. 平台默认租户
 * 2. 系统角色（补充迁移已创建的基础角色）
 * 3. 权限节点（补充迁移已创建的基础权限）
 * 4. 角色-权限映射
 * 5. 超级管理员
 */
class PlatformInitSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=== 平台初始化开始 ===');
        $this->newLine();

        $this->call([
            PlatformTenantSeeder::class,
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
        ]);

        $this->newLine();
        $this->command->info('=== 平台初始化完成 ===');
    }
}
