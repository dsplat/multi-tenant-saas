<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 指标快照表
 *
 * 存储实时采集与多粒度聚合后的指标数据：
 * - 分钟级原始快照（由 CollectMetrics 命令每分钟写入）
 * - 小时/天/月级聚合快照（通过聚合任务回写，aggregated=true）
 *
 * 维度（dimension_type/dimension_value）支持租户/端点/区域。
 * 租户级指标在 tenant_id 字段标记，系统级指标 tenant_id 为 NULL。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('metrics_snapshot_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable(); // 租户 ID（NULL 为系统级指标）
            $table->string('metric_name', 100); // 指标名：requests / latency_p50 / error_rate 等
            $table->double('metric_value')->default(0); // 指标值
            $table->string('dimension_type', 30)->nullable(); // 维度类型：tenant / endpoint / region
            $table->string('dimension_value', 200)->nullable(); // 维度值
            $table->string('granularity', 10)->default('minute'); // 粒度：minute / hour / day / month
            $table->boolean('aggregated')->default(false); // 是否为聚合数据
            $table->timestamp('sampled_at'); // 采样时间（按粒度对齐）
            $table->timestamps();

            $table->index(['metric_name', 'granularity', 'sampled_at']);
            $table->index(['tenant_id', 'metric_name', 'sampled_at']);
            $table->index(['dimension_type', 'dimension_value']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_snapshots');
    }
};
