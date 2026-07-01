<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->primary()->comment('会话会话 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('user_id')->comment('用户 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('status', 20)->default('active')->comment('会话状态: active/idle/disconnected');
            $table->timestamp('connected_at')->nullable()->comment('连接时间');
            $table->timestamp('last_active_at')->nullable()->comment('最后活跃时间');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index(['user_id']);
            $table->index(['tenant_id']);

            $table->foreign('conversation_id')->references('conversation_id')->on('conversations')->onDelete('cascade');
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_sessions');
    }
};
