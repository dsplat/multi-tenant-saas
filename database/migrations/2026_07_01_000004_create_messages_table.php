<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->primary()->comment('消息 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('sender_id')->nullable()->comment('发送者用户ID');
            $table->string('sender_type', 20)->default('user')->comment('发送者类型: user/agent/system');
            $table->string('type', 20)->default('text')->comment('消息类型: text/image/file/system');
            $table->text('content')->nullable()->comment('消息内容');
            $table->json('attachments')->nullable()->comment('附件列表 JSON');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['tenant_id']);
            $table->index(['sender_id']);

            $table->foreign('conversation_id')->references('conversation_id')->on('conversations')->onDelete('cascade');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
