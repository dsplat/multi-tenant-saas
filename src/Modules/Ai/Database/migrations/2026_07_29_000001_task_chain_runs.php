<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_chain_runs — 预设任务链运行实例表（docs/task-chain.md 第四节）
 *
 * 链在会话内推进，状态持久化支持中断续跑。
 * steps_state 含每步状态快照 + 链上下文 KV 包。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_chain_runs')) {
            Schema::create('task_chain_runs', function (Blueprint $table) {
                $table->unsignedBigInteger('run_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('conversation_id')->comment('归属会话（链在会话内推进）');
                $table->string('chain_key', 100)->comment('链定义 key');
                $table->json('steps_state')->comment('每步状态快照 + context KV 包');
                $table->unsignedInteger('current_step')->default(0)->comment('当前步下标（0 起）');
                $table->string('status', 20)->default('running')->comment('running/waiting_input/waiting_confirm/completed/failed/cancelled');
                $table->timestamps();

                $table->index(['tenant_id', 'conversation_id']);
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_chain_runs');
    }
};
