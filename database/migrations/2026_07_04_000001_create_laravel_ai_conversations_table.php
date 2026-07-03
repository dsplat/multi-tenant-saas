<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel/ai 会话表（使用项目 IdGenerator 规范）
     *
     * 与业务层 agent_conversations 表分离，
     * 专供 laravel/ai SDK 的 RemembersConversations 功能使用。
     */
    public function up(): void
    {
        // 会话表
        Schema::create('laravel_ai_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->primary()->comment('会话 ID（IdGenerator 16位数字）');
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户 ID');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户 ID（多租户隔离）');
            $table->string('title')->comment('会话标题');
            $table->string('status', 20)->default('active')->comment('会话状态');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['tenant_id']);
        });

        // 消息表
        Schema::create('laravel_ai_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->primary()->comment('消息 ID（IdGenerator 16位数字）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户 ID');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户 ID（多租户隔离）');
            $table->string('agent')->comment('Agent 类名');
            $table->string('role', 25)->comment('消息角色');
            $table->text('content')->comment('消息内容');
            $table->text('attachments')->default('[]')->comment('附件 JSON');
            $table->text('tool_calls')->default('[]')->comment('工具调用 JSON');
            $table->text('tool_results')->default('[]')->comment('工具结果 JSON');
            $table->text('usage')->default('[]')->comment('Token 用量 JSON');
            $table->text('meta')->default('[]')->comment('元数据 JSON');
            $table->timestamps();

            $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravel_ai_messages');
        Schema::dropIfExists('laravel_ai_conversations');
    }
};
