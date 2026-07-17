<?php

use Illuminate\Database\Migrations\Migration;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 添加 SCRM 业务权限和 8 个对应 AI Agent 角色的系统级租户角色
 *
 * 角色 ←→ AI Agent 映射:
 *   sales               ← SalesAgent              (销售)
 *   customer_service    ← CustomerServiceAgent   (客服)
 *   marketing           ← MarketingPlanningAgent  (营销策划)
 *   customer_insight    ← CustomerInsightAgent    (客户洞察)
 *   operations          ← OperationsAgent         (运营)
 *   quality_inspection  ← QualityInspectionAgent  (质检)
 *   data_analysis       ← DataAnalysisAgent       (数据分析)
 *   design_production   ← DesignProductionAgent   (设计制作)
 */
return new class extends Migration
{
    private array $businessPermissions = [
        // 客户管理
        ['name' => 'customer.view',    'display_name' => '查看客户',   'group' => 'customer', 'description' => '查看客户列表和详情'],
        ['name' => 'customer.update',  'display_name' => '更新客户',   'group' => 'customer', 'description' => '更新客户信息'],
        ['name' => 'customer.export',  'display_name' => '导出客户',   'group' => 'customer', 'description' => '导出客户数据'],

        // 会话管理
        ['name' => 'conversation.view',   'display_name' => '查看会话',   'group' => 'conversation', 'description' => '查看对话记录'],
        ['name' => 'conversation.reply',   'display_name' => '回复会话',   'group' => 'conversation', 'description' => '回复客户消息'],
        ['name' => 'conversation.export',  'display_name' => '导出会话',   'group' => 'conversation', 'description' => '导出对话记录'],

        // 联系人
        ['name' => 'contact.view',   'display_name' => '查看联系人', 'group' => 'contact', 'description' => '查看联系人列表'],
        ['name' => 'contact.update', 'display_name' => '更新联系人', 'group' => 'contact', 'description' => '更新联系人信息'],

        // 标签管理
        ['name' => 'tag.view',   'display_name' => '查看标签', 'group' => 'tag', 'description' => '查看标签体系'],
        ['name' => 'tag.manage', 'display_name' => '管理标签', 'group' => 'tag', 'description' => '创建/编辑/删除标签'],

        // 营销活动
        ['name' => 'campaign.view',   'display_name' => '查看活动',   'group' => 'campaign', 'description' => '查看营销活动'],
        ['name' => 'campaign.create',  'display_name' => '创建活动',   'group' => 'campaign', 'description' => '创建营销活动'],
        ['name' => 'campaign.update',  'display_name' => '更新活动',   'group' => 'campaign', 'description' => '更新营销活动'],
        ['name' => 'campaign.delete',  'display_name' => '删除活动',   'group' => 'campaign', 'description' => '删除营销活动'],

        // 素材管理
        ['name' => 'material.view',   'display_name' => '查看素材', 'group' => 'material', 'description' => '查看营销素材'],
        ['name' => 'material.create',  'display_name' => '创建素材', 'group' => 'material', 'description' => '上传/创建素材'],
        ['name' => 'material.update',  'display_name' => '更新素材', 'group' => 'material', 'description' => '更新素材信息'],
        ['name' => 'material.delete',  'display_name' => '删除素材', 'group' => 'material', 'description' => '删除素材'],

        // 模板管理
        ['name' => 'template.view',   'display_name' => '查看模板', 'group' => 'template', 'description' => '查看消息/文案模板'],
        ['name' => 'template.create', 'display_name' => '创建模板', 'group' => 'template', 'description' => '创建模板'],

        // 渠道管理
        ['name' => 'channel.view', 'display_name' => '查看渠道', 'group' => 'channel', 'description' => '查看渠道配置'],
        ['name' => 'channel.send', 'display_name' => '发送消息', 'group' => 'channel', 'description' => '通过渠道发送消息'],

        // 社群管理
        ['name' => 'community.view',   'display_name' => '查看社群',   'group' => 'community', 'description' => '查看社群列表'],
        ['name' => 'community.manage', 'display_name' => '管理社群',   'group' => 'community', 'description' => '管理社群配置和成员'],

        // 群发管理
        ['name' => 'broadcast.view',   'display_name' => '查看群发',   'group' => 'broadcast', 'description' => '查看群发任务'],
        ['name' => 'broadcast.create', 'display_name' => '创建群发',   'group' => 'broadcast', 'description' => '创建群发任务'],
        ['name' => 'broadcast.send',   'display_name' => '执行群发',   'group' => 'broadcast', 'description' => '执行群发发送'],

        // SOP 管理
        ['name' => 'sop.view',   'display_name' => '查看SOP',   'group' => 'sop', 'description' => '查看SOP流程'],
        ['name' => 'sop.manage', 'display_name' => '管理SOP',   'group' => 'sop', 'description' => '创建/编辑/删除SOP'],

        // 质检管理
        ['name' => 'quality.view',   'display_name' => '查看质检',   'group' => 'quality', 'description' => '查看质检结果'],
        ['name' => 'quality.manage', 'display_name' => '管理质检',   'group' => 'quality', 'description' => '配置质检规则和标准'],

        // 合规管理
        ['name' => 'compliance.view',   'display_name' => '查看合规',   'group' => 'compliance', 'description' => '查看合规审核记录'],
        ['name' => 'compliance.manage', 'display_name' => '管理合规',   'group' => 'compliance', 'description' => '配置合规规则和敏感词'],

        // 报表管理
        ['name' => 'report.view',   'display_name' => '查看报表',   'group' => 'report', 'description' => '查看业务报表'],
        ['name' => 'report.create', 'display_name' => '创建报表',   'group' => 'report', 'description' => '创建自定义报表'],
        ['name' => 'report.export', 'display_name' => '导出报表',   'group' => 'report', 'description' => '导出报表数据'],

        // 仪表盘
        ['name' => 'dashboard.view', 'display_name' => '查看仪表盘', 'group' => 'dashboard', 'description' => '查看数据仪表盘'],

        // 数据分析
        ['name' => 'analytics.view', 'display_name' => '数据分析', 'group' => 'analytics', 'description' => '查看数据分析结果'],

        // 客户洞察
        ['name' => 'insight.view', 'display_name' => '客户洞察', 'group' => 'insight', 'description' => '查看客户画像、情感分析、流失预测'],

        // 素材库
        ['name' => 'asset.view',   'display_name' => '查看素材库', 'group' => 'asset', 'description' => '查看素材资源库'],
        ['name' => 'asset.upload', 'display_name' => '上传素材',  'group' => 'asset', 'description' => '上传素材到资源库'],

        // 工单管理
        ['name' => 'ticket.view',   'display_name' => '查看工单', 'group' => 'ticket', 'description' => '查看客服工单'],
        ['name' => 'ticket.create', 'display_name' => '创建工单', 'group' => 'ticket', 'description' => '创建客服工单'],
        ['name' => 'ticket.update', 'display_name' => '更新工单', 'group' => 'ticket', 'description' => '更新工单状态'],

        // FAQ
        ['name' => 'faq.view', 'display_name' => '查看FAQ', 'group' => 'faq', 'description' => '查看常见问题知识库'],

        // 销售管理
        ['name' => 'sales.view',   'display_name' => '查看销售', 'group' => 'sales', 'description' => '查看销售计划和跟进记录'],
        ['name' => 'sales.update', 'display_name' => '更新销售', 'group' => 'sales', 'description' => '更新销售计划和跟进记录'],
    ];

    private array $businessRoles = [
        ['name' => 'sales',              'display_name' => '销售',     'description' => '对应 SalesAgent — 客户意向分析、跟进话术推荐和销售计划管理'],
        ['name' => 'customer_service',   'display_name' => '客服',     'description' => '对应 CustomerServiceAgent — 客户咨询、问题解答、售后支持和投诉处理'],
        ['name' => 'marketing',          'display_name' => '营销策划', 'description' => '对应 MarketingPlanningAgent — 营销内容生成、话术推荐、活动策划和数据分析'],
        ['name' => 'customer_insight',   'display_name' => '客户洞察', 'description' => '对应 CustomerInsightAgent — 客户情感分析、流失预测、画像分析和对话质量评估'],
        ['name' => 'operations',         'display_name' => '运营',     'description' => '对应 OperationsAgent — 社群运营、群发任务编排、SOP执行和数据分析'],
        ['name' => 'quality_inspection', 'display_name' => '质检',     'description' => '对应 QualityInspectionAgent — 对话质量检测、合规审核、敏感词拦截和客户情绪监控'],
        ['name' => 'data_analysis',      'display_name' => '数据分析', 'description' => '对应 DataAnalysisAgent — 客户分析、流失预测、意向判断、趋势分析和报表生成'],
        ['name' => 'design_production',  'display_name' => '设计制作', 'description' => '对应 DesignProductionAgent — 营销素材设计、图片提示词生成、视频脚本制作和内容创作'],
    ];

    /**
     * 角色 → 权限映射
     */
    private array $rolePermissionMap = [
        'sales' => [
            'customer.view', 'customer.update', 'contact.view', 'contact.update',
            'conversation.view', 'sales.view', 'sales.update',
            'tag.view', 'dashboard.view', 'report.view',
            'audit.view', 'file.upload',
        ],
        'customer_service' => [
            'customer.view', 'customer.update',
            'conversation.view', 'conversation.reply',
            'ticket.view', 'ticket.create', 'ticket.update',
            'faq.view', 'tag.view',
            'file.upload', 'audit.view',
        ],
        'marketing' => [
            'campaign.view', 'campaign.create', 'campaign.update', 'campaign.delete',
            'material.view', 'material.create', 'material.update',
            'template.view', 'template.create',
            'channel.view', 'channel.send',
            'customer.view',
            'file.upload', 'audit.view', 'dashboard.view',
        ],
        'customer_insight' => [
            'customer.view', 'customer.export',
            'tag.view', 'tag.manage',
            'insight.view', 'analytics.view',
            'report.view', 'dashboard.view',
            'audit.view',
        ],
        'operations' => [
            'community.view', 'community.manage',
            'broadcast.view', 'broadcast.create', 'broadcast.send',
            'sop.view', 'sop.manage',
            'channel.view',
            'customer.view',
            'file.upload', 'audit.view', 'dashboard.view',
        ],
        'quality_inspection' => [
            'conversation.view', 'conversation.export',
            'quality.view', 'quality.manage',
            'compliance.view', 'compliance.manage',
            'report.view', 'dashboard.view',
            'audit.view',
        ],
        'data_analysis' => [
            'report.view', 'report.create', 'report.export',
            'dashboard.view', 'analytics.view',
            'customer.view', 'customer.export',
            'audit.view',
        ],
        'design_production' => [
            'material.view', 'material.create', 'material.update', 'material.delete',
            'template.view', 'template.create',
            'asset.view', 'asset.upload',
            'file.upload', 'file.delete',
            'audit.view',
        ],
    ];

    public function up(): void
    {
        $now = now();
        $idGenerator = app(IdGeneratorContract::class);

        // 1. 插入业务权限（跳过已存在的）
        $existingPermNames = DB::table('permissions')->whereIn('name', array_column($this->businessPermissions, 'name'))->pluck('name')->toArray();
        $newPermissions = [];
        foreach ($this->businessPermissions as $p) {
            if (in_array($p['name'], $existingPermNames)) {
                continue;
            }
            $p['permission_id'] = $idGenerator->generate();
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
            $newPermissions[] = $p;
        }
        if (! empty($newPermissions)) {
            DB::table('permissions')->insert($newPermissions);
        }

        // 2. 插入 8 个业务角色（系统级，tenant_id = null）— 跳过已存在的
        $existingRoleNames = DB::table('roles')->whereIn('name', array_column($this->businessRoles, 'name'))->whereNull('tenant_id')->pluck('name')->toArray();
        $newRoles = [];
        foreach ($this->businessRoles as $r) {
            if (in_array($r['name'], $existingRoleNames)) {
                continue;
            }
            $r['role_id'] = $idGenerator->generate();
            $r['tenant_id'] = null;
            $r['is_system'] = true;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            $newRoles[] = $r;
        }
        if (! empty($newRoles)) {
            DB::table('roles')->insert($newRoles);
        }

        // 3. 为每个角色分配权限（跳过已存在的关联）
        foreach ($this->rolePermissionMap as $roleName => $permNames) {
            $roleId = DB::table('roles')->where('name', $roleName)->whereNull('tenant_id')->value('role_id');
            if (! $roleId) {
                continue;
            }

            $existingPermIds = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', function ($query) use ($permNames) {
                    $query->select('permission_id')
                        ->from('permissions')
                        ->whereIn('name', $permNames);
                })
                ->pluck('permission_id')
                ->toArray();

            $permIds = DB::table('permissions')->whereIn('name', $permNames)
                ->whereNotIn('permission_id', $existingPermIds)
                ->pluck('permission_id');

            if ($permIds->isEmpty()) {
                continue;
            }

            $insert = $permIds->map(fn ($pid) => [
                'role_id' => $roleId,
                'permission_id' => $pid,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('role_permissions')->insert($insert);
        }

        // 4. 为 super_admin 分配所有新权限（仅插入未关联的）
        $allBusinessPermIds = DB::table('permissions')
            ->whereIn('name', array_column($this->businessPermissions, 'name'))
            ->pluck('permission_id');

        $superRoleId = DB::table('roles')->where('name', 'super_admin')->whereNull('tenant_id')->value('role_id');
        if ($superRoleId) {
            $existingSuperPermIds = DB::table('role_permissions')
                ->where('role_id', $superRoleId)
                ->whereIn('permission_id', $allBusinessPermIds)
                ->pluck('permission_id')
                ->toArray();

            $toAdd = $allBusinessPermIds->filter(fn ($pid) => ! in_array($pid, $existingSuperPermIds));
            if ($toAdd->isNotEmpty()) {
                $insertSuper = $toAdd->map(fn ($pid) => [
                    'role_id' => $superRoleId,
                    'permission_id' => $pid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                DB::table('role_permissions')->insert($insertSuper);
            }
        }

        // 5. 为 tenant_admin 分配所有新权限（仅插入未关联的）
        $adminRoleId = DB::table('roles')->where('name', 'tenant_admin')->whereNull('tenant_id')->value('role_id');
        if ($adminRoleId) {
            $existingAdminPermIds = DB::table('role_permissions')
                ->where('role_id', $adminRoleId)
                ->whereIn('permission_id', $allBusinessPermIds)
                ->pluck('permission_id')
                ->toArray();

            $toAdd = $allBusinessPermIds->filter(fn ($pid) => ! in_array($pid, $existingAdminPermIds));
            if ($toAdd->isNotEmpty()) {
                $insertAdmin = $toAdd->map(fn ($pid) => [
                    'role_id' => $adminRoleId,
                    'permission_id' => $pid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                DB::table('role_permissions')->insert($insertAdmin);
            }
        }
    }

    public function down(): void
    {
        $permNames = array_column($this->businessPermissions, 'name');
        $roleNames = array_column($this->businessRoles, 'name');

        $permIds = DB::table('permissions')->whereIn('name', $permNames)->pluck('permission_id');
        $roleIds = DB::table('roles')->whereIn('name', $roleNames)->whereNull('tenant_id')->pluck('role_id');

        DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('name', $roleNames)->whereNull('tenant_id')->delete();
        DB::table('permissions')->whereIn('name', $permNames)->delete();
    }
};
