<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign → ActivityPlan 全面去名化（表改名 + 存量刷值，幂等）
 *
 * 1. campaign_plans → activity_plans、campaign_tasks → activity_tasks（RENAME 保数据，生产有真实计划）
 * 2. plan_doc JSON 内嵌 schema 标识与实体类型刷值（PlanCompiler 硬校验 activity.plan/v1）
 * 3. agents.tools JSON / agent_tools.slug / ai_tasks.type 中工具 slug 刷值
 *    （campaign_plan_draft/commit → activity_plan_draft/commit，campaign_status → activity_status）
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. 表改名（幂等：源表存在且目标表不存在时才执行）
        if (Schema::hasTable('campaign_plans') && ! Schema::hasTable('activity_plans')) {
            Schema::rename('campaign_plans', 'activity_plans');
        }
        if (Schema::hasTable('campaign_tasks') && ! Schema::hasTable('activity_tasks')) {
            Schema::rename('campaign_tasks', 'activity_tasks');
        }

        // 2. plan_doc 内嵌字串刷值（已知陷阱：schema 标识与实体类型嵌在 JSON 中）
        if (Schema::hasTable('activity_plans')) {
            DB::table('activity_plans')->where('plan_doc', 'like', '%campaign%')->update([
                'plan_doc' => DB::raw("REPLACE(REPLACE(plan_doc, 'campaign.plan/v1', 'activity.plan/v1'), 'campaign_plan', 'activity_plan')"),
            ]);
        }

        // 3. agent 存量工具引用刷值（带引号精确匹配 JSON 数组元素，thread 三工具不受影响）
        $slugMap = [
            '"campaign_plan_draft"' => '"activity_plan_draft"',
            '"campaign_plan_commit"' => '"activity_plan_commit"',
            '"campaign_status"' => '"activity_status"',
        ];

        if (Schema::hasTable('agents')) {
            foreach ($slugMap as $from => $to) {
                DB::table('agents')->where('tools', 'like', '%' . $from . '%')->update([
                    'tools' => DB::raw("REPLACE(tools, '{$from}', '{$to}')"),
                ]);
            }
        }

        if (Schema::hasTable('agent_tools')) {
            $plain = [
                'campaign_plan_draft' => 'activity_plan_draft',
                'campaign_plan_commit' => 'activity_plan_commit',
                'campaign_status' => 'activity_status',
            ];
            foreach ($plain as $from => $to) {
                DB::table('agent_tools')->where('slug', $from)->update(['slug' => $to]);
            }
        }

        // 4. 排队中的任务化长工具类型刷值
        if (Schema::hasTable('ai_tasks')) {
            DB::table('ai_tasks')->where('type', 'campaign_plan_draft')->update(['type' => 'activity_plan_draft']);
        }

        // 5. 权限字典刷值（campaign.* → activity.*，种子数据已同步）
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name', 'like', 'campaign.%')->update([
                'name' => DB::raw("REPLACE(name, 'campaign.', 'activity.')"),
                'group' => 'activity',
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name', 'like', 'activity.%')->update([
                'name' => DB::raw("REPLACE(name, 'activity.', 'campaign.')"),
                'group' => 'campaign',
            ]);
        }

        if (Schema::hasTable('ai_tasks')) {
            DB::table('ai_tasks')->where('type', 'activity_plan_draft')->update(['type' => 'campaign_plan_draft']);
        }

        if (Schema::hasTable('agent_tools')) {
            foreach (['activity_plan_draft' => 'campaign_plan_draft', 'activity_plan_commit' => 'campaign_plan_commit', 'activity_status' => 'campaign_status'] as $from => $to) {
                DB::table('agent_tools')->where('slug', $from)->update(['slug' => $to]);
            }
        }

        if (Schema::hasTable('agents')) {
            foreach (['"activity_plan_draft"' => '"campaign_plan_draft"', '"activity_plan_commit"' => '"campaign_plan_commit"', '"activity_status"' => '"campaign_status"'] as $from => $to) {
                DB::table('agents')->where('tools', 'like', '%' . $from . '%')->update([
                    'tools' => DB::raw("REPLACE(tools, '{$from}', '{$to}')"),
                ]);
            }
        }

        if (Schema::hasTable('activity_plans')) {
            DB::table('activity_plans')->where('plan_doc', 'like', '%activity.plan/v1%')->update([
                'plan_doc' => DB::raw("REPLACE(REPLACE(plan_doc, 'activity.plan/v1', 'campaign.plan/v1'), 'activity_plan', 'campaign_plan')"),
            ]);
        }

        if (Schema::hasTable('activity_tasks') && ! Schema::hasTable('campaign_tasks')) {
            Schema::rename('activity_tasks', 'campaign_tasks');
        }
        if (Schema::hasTable('activity_plans') && ! Schema::hasTable('campaign_plans')) {
            Schema::rename('activity_plans', 'campaign_plans');
        }
    }
};
