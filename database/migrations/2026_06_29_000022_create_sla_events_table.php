<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA 事件表
 *
 * 记录可用性相关事件（停机/降级/维护），用于：
 * - 可用性计算（uptime / total * 100）
 * - SLA 达标率统计（月/季/年）
 * - 违约事件追溯
 *
 * 事件期间受影响范围通过 affected_scope（如 "tenant:1001" / "region:us-east" / "global"）
 * 与 affected_count 描述。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_events', function (Blueprint $table) {
            $table->unsignedBigInteger('sla_event_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable(); // 租户 ID（NULL 为系统级事件）
            $table->string('event_type', 20); // 事件类型：downtime / degradation / maintenance
            $table->string('severity', 20)->default('warning'); // 严重级别：info / warning / critical / fatal
            $table->string('affected_scope', 100)->default('global'); // 受影响范围
            $table->unsignedInteger('affected_count')->default(0); // 受影响数量
            $table->timestamp('started_at'); // 开始时间
            $table->timestamp('ended_at')->nullable(); // 结束时间（NULL 表示进行中）
            $table->unsignedInteger('duration_sec')->default(0); // 持续秒数
            $table->string('status', 20)->default('active'); // 状态：active / resolved
            $table->text('root_cause')->nullable(); // 根因分析
            $table->text('resolution_notes')->nullable(); // 解决说明
            $table->json('metadata')->nullable(); // 附加元数据
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'started_at']);
            $table->index(['event_type', 'started_at']);
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_events');
    }
};
