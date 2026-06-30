<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversation_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->primary()->comment('消息 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->enum('role', ['user', 'assistant', 'tool', 'system'])->comment('消息角色');
            $table->text('content')->nullable()->comment('消息内容');
            $table->json('tool_calls')->nullable()->comment('工具调用（OpenAI 结构）');
            $table->string('tool_call_id', 100)->nullable()->comment('工具调用 ID（tool 角色消息）');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->index(['conversation_id']);
            $table->index(['conversation_id', 'created_at']);

            // idx_conversation 由上面的 index 覆盖；外键复用该索引
            $table->foreign('conversation_id')->references('conversation_id')->on('agent_conversations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_messages');
    }
};
