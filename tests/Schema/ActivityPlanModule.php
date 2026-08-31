<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ActivityPlan 模块
 * 表: activity_plans, activity_tasks
 */
class ActivityPlanModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('activity_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('anchor_type', 50)->nullable();
            $table->unsignedBigInteger('anchor_id')->nullable();
            $table->json('plan_doc');
            $table->string('status', 20)->default('planning');
            $table->string('playbook_key', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('activity_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('task_key', 100);
            $table->string('title', 255);
            $table->string('phase_key', 100)->nullable();
            $table->string('trigger_type', 20);
            $table->timestamp('scheduled_at')->nullable();
            $table->string('listen_event', 100)->nullable();
            $table->string('assignee_type', 20)->default('system');
            $table->string('assignee_ref', 100)->nullable();
            $table->json('action');
            $table->string('execution_mode', 20)->default('auto');
            $table->json('depends_on')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('output')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'plan_id']);
            $table->index(['status', 'scheduled_at']);
            $table->unique(['plan_id', 'task_key'], 'activity_tasks_plan_key_unique');
        });

        Schema::create('activity_task_completions', function (Blueprint $table) {
            $table->unsignedBigInteger('completion_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('completed_at');
            $table->json('output')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'task_id', 'user_id'], 'task_completions_unique');
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['activity_plans', 'activity_tasks', 'activity_task_completions'];
    }
}
