<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign 模块
 * 表: campaign_plans, campaign_tasks
 */
class CampaignModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('campaign_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('anchor_type', 50)->nullable();
            $table->unsignedBigInteger('anchor_id')->nullable();
            $table->json('plan_doc');
            $table->string('status', 20)->default('planning');
            $table->string('playbook_key', 100)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('campaign_tasks', function (Blueprint $table) {
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
            $table->unique(['plan_id', 'task_key'], 'campaign_tasks_plan_key_unique');
        });
    }

    public function getTableNames(): array
    {
        return ['campaign_plans', 'campaign_tasks'];
    }
}
