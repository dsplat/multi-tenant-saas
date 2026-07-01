<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->primary()->comment('会话 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建者用户ID');
            $table->string('type', 20)->default('support')->comment('会话类型: support/group/direct');
            $table->string('status', 20)->default('active')->comment('会话状态: active/closed/archived');
            $table->string('title', 255)->nullable()->comment('会话标题');
            $table->string('channel', 20)->default('web')->comment('会话渠道');
            $table->unsignedBigInteger('agent_id')->nullable()->comment('分配的 Agent ID');
            $table->timestamp('last_message_at')->nullable()->comment('最后消息时间');
            $table->integer('message_count')->default(0)->comment('消息计数');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['created_by']);
            $table->index(['agent_id']);
            $table->index(['last_message_at']);

            $table->foreign('agent_id')->references('agent_id')->on('agents')->onDelete('set null');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('created_by')->references('user_id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
