<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TaskChain 模块
 * 表: task_chain_runs
 */
class TaskChainModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('task_chain_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('run_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('chain_key', 100);
            $table->json('steps_state');
            $table->unsignedInteger('current_step')->default(0);
            $table->string('status', 20)->default('running');
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function getTableNames(): array
    {
        return ['task_chain_runs'];
    }
}
