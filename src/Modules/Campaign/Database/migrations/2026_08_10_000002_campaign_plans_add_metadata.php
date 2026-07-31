<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * campaign_plans 增加 metadata 列（项目大脑·工作脉络跟踪）
 *
 * metadata.tracked：脉络跟踪标记（campaign_plan_commit 定稿自动置位 /
 *                   thread_track 经确认门建立），巡检与摘要注入只扫 tracked 脉络
 * metadata.health：thread:health-check 巡检结果（逾期/停滞/建议）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_plans') && ! Schema::hasColumn('campaign_plans', 'metadata')) {
            Schema::table('campaign_plans', function (Blueprint $table) {
                $table->json('metadata')->nullable()
                    ->comment('扩展元数据（tracked 跟踪标记 / health 巡检结果）')
                    ->after('playbook_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campaign_plans') && Schema::hasColumn('campaign_plans', 'metadata')) {
            Schema::table('campaign_plans', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
