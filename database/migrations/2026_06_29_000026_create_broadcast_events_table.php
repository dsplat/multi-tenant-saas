<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 广播事件表
 *
 * 记录通过 WebSocket（Reverb/Pusher/Soketi）实时推送的事件，
 * 包含租户级频道、事件类型、负载及发送状态，用于审计与重试。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_events', function (Blueprint $table) {
            $table->unsignedBigInteger('broadcast_event_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable()->index(); // 租户 ID（系统级广播可空）
            $table->string('event_type', 100); // 事件类型：ai_video_completed / system_announcement / online_status 等
            $table->string('channel', 200); // 频道名称，如 private-tenant.{tenantId}.{userId}
            $table->json('payload'); // 负载数据
            $table->boolean('is_sent')->default(false); // 是否已发送
            $table->text('error_message')->nullable(); // 发送失败原因
            $table->timestamp('sent_at')->nullable(); // 发送时间
            $table->timestamps();

            $table->index(['tenant_id', 'event_type', 'is_sent'], 'idx_tenant_event_sent');
            $table->index('channel');
            $table->index('is_sent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_events');
    }
};
