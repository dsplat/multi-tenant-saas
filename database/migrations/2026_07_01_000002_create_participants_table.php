<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->primary()->comment('参与者 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('user_id')->comment('用户 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('role', 20)->default('member')->comment('参与者角色: member/agent/admin/guest');
            $table->boolean('is_muted')->default(false)->comment('是否静音');
            $table->timestamp('left_at')->nullable()->comment('离开时间');
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
        Schema::dropIfExists('participants');
    }
};
