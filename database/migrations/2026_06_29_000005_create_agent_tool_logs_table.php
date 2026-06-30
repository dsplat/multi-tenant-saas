<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary()->comment('日志 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('agent_id')->comment('Agent ID');
            $table->string('tool_name', 100)->comment('工具名称');
            $table->json('input')->nullable()->comment('工具输入参数');
            $table->json('output')->nullable()->comment('工具输出');
            $table->integer('duration_ms')->default(0)->comment('执行耗时（毫秒）');
            $table->string('status', 20)->default('success')->comment('调用状态');
            $table->text('error')->nullable()->comment('错误信息');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->index(['conversation_id']);
            $table->index(['agent_id']);
            $table->index(['tool_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_logs');
    }
};
