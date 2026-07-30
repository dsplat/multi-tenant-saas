<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * campaign 模块 — 活动排期引擎两表（docs/event-plan.md 第四节）
 *
 * campaign_plans：排期计划（plan_doc JSON 描述任务蓝图，compile 后生成 tasks）
 * campaign_tasks：编译产出的可调度任务（at_time 定时 / on_event 事件触发）
 *
 * Phase 0 范围：relative/at_time 触发 + tool/human 执行；
 * recurring/on_event 执行、agent ReAct、task_chain 为 Phase 2。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_plans')) {
            Schema::create('campaign_plans', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('anchor_type', 50)->nullable()->comment('锚点业务对象类型（如 event）');
                $table->unsignedBigInteger('anchor_id')->nullable()->comment('锚点业务对象 ID');
                $table->json('plan_doc')->comment('计划文档（campaign.plan/v1 schema）');
                $table->string('status', 20)->default('planning')->comment('planning/scheduled/running/reviewing/closed/cancelled');
                $table->string('playbook_key', 100)->nullable()->comment('关联 playbook 标识（可选）');
                $table->unsignedBigInteger('created_by')->comment('创建者 operator_id');
                $table->timestamps();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('campaign_tasks')) {
            Schema::create('campaign_tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('task_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('plan_id');
                $table->string('task_key', 100)->comment('计划内唯一任务标识');
                $table->string('title', 255)->comment('任务标题');
                $table->string('phase_key', 100)->nullable()->comment('所属阶段标识');
                $table->string('trigger_type', 20)->comment('at_time/on_event');
                $table->timestamp('scheduled_at')->nullable()->comment('计划执行时间（at_time 触发）');
                $table->string('listen_event', 100)->nullable()->comment('监听事件名（on_event 触发，Phase 0 仅落字段）');
                $table->string('assignee_type', 20)->default('system')->comment('system/human/agent');
                $table->string('assignee_ref', 100)->nullable()->comment('执行者引用（operator_id/agent_id）');
                $table->json('action')->comment('执行动作（type=tool 时含 slug/args）');
                $table->string('execution_mode', 20)->default('auto')->comment('auto/require_confirm');
                $table->json('depends_on')->nullable()->comment('依赖任务 key 列表');
                $table->string('status', 20)->default('pending')->comment('pending/awaiting_confirm/running/done/failed/skipped/cancelled');
                $table->json('output')->nullable()->comment('执行产出');
                $table->timestamp('executed_at')->nullable()->comment('实际执行时间');
                $table->timestamps();

                $table->index(['tenant_id', 'plan_id']);
                $table->index(['status', 'scheduled_at']);
                $table->unique(['plan_id', 'task_key'], 'campaign_tasks_plan_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_tasks');
        Schema::dropIfExists('campaign_plans');
    }
};
