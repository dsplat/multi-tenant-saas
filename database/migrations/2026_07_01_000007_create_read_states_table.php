<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('read_states', function (Blueprint $table) {
            $table->unsignedBigInteger('read_state_id')->primary()->comment('已读状态 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('user_id')->comment('用户 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('last_read_message_id')->nullable()->comment('最后已读消息 ID');
            $table->integer('unread_count')->default(0)->comment('未读消息数');
            $table->timestamp('last_read_at')->nullable()->comment('最后已读时间');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id']);
            $table->index(['tenant_id']);

            $table->foreign('conversation_id')->references('conversation_id')->on('conversations')->onDelete('cascade');
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('read_states');
    }
};
