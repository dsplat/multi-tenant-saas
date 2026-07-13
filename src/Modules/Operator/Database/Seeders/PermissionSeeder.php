<?php

namespace MultiTenantSaas\Modules\Operator\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 权限节点 Seeder
 *
 * 补充 RBAC 迁移（22 个）和抽奖/投票/表单/优惠券迁移（20 个）已创建的权限，
 * 新增 20 个权限节点，总计 42 个。仅创建不存在的权限，不会覆盖已有数据。
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        $permissions = [
            // 工作流模块
            ['name' => 'workflow.view', 'display_name' => '查看工作流', 'group' => 'workflow', 'description' => '查看工作流'],
            ['name' => 'workflow.create', 'display_name' => '创建工作流', 'group' => 'workflow', 'description' => '创建工作流'],
            ['name' => 'workflow.update', 'display_name' => '更新工作流', 'group' => 'workflow', 'description' => '更新工作流'],
            ['name' => 'workflow.delete', 'display_name' => '删除工作流', 'group' => 'workflow', 'description' => '删除工作流'],
            ['name' => 'workflow.execute', 'display_name' => '执行工作流', 'group' => 'workflow', 'description' => '执行工作流'],

            // 会话模块
            ['name' => 'conversation.view', 'display_name' => '查看会话', 'group' => 'conversation', 'description' => '查看会话记录'],
            ['name' => 'conversation.create', 'display_name' => '创建会话', 'group' => 'conversation', 'description' => '创建新会话'],
            ['name' => 'conversation.delete', 'display_name' => '删除会话', 'group' => 'conversation', 'description' => '删除会话'],

            // 模块管理
            ['name' => 'module.view', 'display_name' => '查看模块', 'group' => 'module', 'description' => '查看已安装模块'],
            ['name' => 'module.install', 'display_name' => '安装模块', 'group' => 'module', 'description' => '安装新模块'],
            ['name' => 'module.uninstall', 'display_name' => '卸载模块', 'group' => 'module', 'description' => '卸载模块'],
            ['name' => 'module.configure', 'display_name' => '配置模块', 'group' => 'module', 'description' => '配置模块设置'],

            // 通知模块
            ['name' => 'notification.view', 'display_name' => '查看通知', 'group' => 'notification', 'description' => '查看通知'],
            ['name' => 'notification.send', 'display_name' => '发送通知', 'group' => 'notification', 'description' => '发送通知'],
            ['name' => 'notification.manage', 'display_name' => '管理通知', 'group' => 'notification', 'description' => '管理通知模板和设置'],

            // API Token
            ['name' => 'api_token.view', 'display_name' => '查看Token', 'group' => 'api_token', 'description' => '查看 API Token'],
            ['name' => 'api_token.create', 'display_name' => '创建Token', 'group' => 'api_token', 'description' => '创建 API Token'],
            ['name' => 'api_token.revoke', 'display_name' => '撤销Token', 'group' => 'api_token', 'description' => '撤销 API Token'],

            // 报表
            ['name' => 'report.view', 'display_name' => '查看报表', 'group' => 'report', 'description' => '查看数据报表'],
            ['name' => 'report.export', 'display_name' => '导出报表', 'group' => 'report', 'description' => '导出数据报表'],
        ];

        $created = 0;

        foreach ($permissions as $perm) {
            $exists = DB::table('permissions')
                ->where('name', $perm['name'])
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'permission_id' => $idGenerator->generate(),
                    'name' => $perm['name'],
                    'display_name' => $perm['display_name'],
                    'group' => $perm['group'],
                    'description' => $perm['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        $this->command->info("权限节点已创建: {$created} 个新权限");
    }
}
