<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_tag_id')->primary()->comment('标签 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('tag', 50)->comment('标签名称');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->unique(['conversation_id', 'tag']);
            $table->index(['tenant_id', 'tag']);
            $table->index(['tenant_id']);

            $table->foreign('conversation_id')->references('conversation_id')->on('conversations')->onDelete('cascade');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tags');
    }
};
