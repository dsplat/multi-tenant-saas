<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 站内通知表
 *
 * 存储租户内用户的站内通知记录，支持通知分类（系统/账单/AI/安全）、
 * 已读/未读状态、批量标记已读及跳转链接。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('in_app_notification_id')->primary();
            $table->unsignedBigInteger('tenant_id')->index(); // 租户 ID
            $table->unsignedBigInteger('user_id')->index(); // 接收用户 ID
            $table->string('type', 30)->default('system'); // 通知分类：system / bill / ai / security
            $table->string('title', 200); // 通知标题
            $table->text('body')->nullable(); // 通知内容
            $table->string('link', 500)->nullable(); // 跳转链接
            $table->boolean('is_read')->default(false); // 是否已读
            $table->timestamp('read_at')->nullable(); // 已读时间
            $table->json('metadata')->nullable(); // 扩展数据
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'is_read'], 'idx_tenant_user_read');
            $table->index(['tenant_id', 'user_id', 'type'], 'idx_tenant_user_type');
            $table->index(['user_id', 'is_read'], 'idx_user_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};
