<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->primary()->comment('会话 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('agent_id')->comment('Agent ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID（业务层）');
            $table->unsignedBigInteger('staff_id')->nullable()->comment('坐席ID（业务层）');
            $table->string('channel', 20)->default('web')->comment('会话渠道');
            $table->string('subject', 255)->nullable()->comment('会话主题');
            $table->string('status', 20)->default('active')->comment('会话状态');
            $table->text('summary')->nullable()->comment('会话摘要');
            $table->json('token_usage')->nullable()->comment('Token 用量统计');
            $table->integer('message_count')->default(0)->comment('消息计数');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->index(['agent_id']);
            $table->index(['tenant_id']);
            $table->index(['customer_id']);
            $table->index(['status']);

            // idx_agent 由上面的 index 覆盖；外键复用该索引（MySQL 不会重复建索引）
            $table->foreign('agent_id')->references('agent_id')->on('agents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversations');
    }
};
