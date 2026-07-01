<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->unsignedBigInteger('mention_id')->primary()->comment('提及 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('message_id')->comment('消息 ID');
            $table->unsignedBigInteger('user_id')->comment('被提及用户 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->boolean('is_notified')->default(false)->comment('是否已通知');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'is_notified']);
            $table->index(['tenant_id']);

            $table->foreign('message_id')->references('message_id')->on('messages')->onDelete('cascade');
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
