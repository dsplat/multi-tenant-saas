<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学员侧任务完成记录（ActivityTask assignee_type='user' 扩展）
 *
 * 排期引擎原语义为"运营动作排期"（assignee_type=system/human/agent）；
 * 扩展 user 语义后：每日学习任务/训练营营期任务由学员完成，
 * 完成记录落本表（一个学员对一个任务仅一条，幂等）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_task_completions')) {
            Schema::create('activity_task_completions', function (Blueprint $table) {
                $table->unsignedBigInteger('completion_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('task_id')->comment('activity_tasks.task_id');
                $table->unsignedBigInteger('user_id')->comment('完成学员 user_id');
                $table->timestamp('completed_at')->comment('完成时间');
                $table->json('output')->nullable()->comment('完成产出（学习时长/提交 ID 等，业务自定义）');
                $table->timestamps();

                $table->unique(['tenant_id', 'task_id', 'user_id'], 'task_completions_unique');
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_task_completions');
    }
};
