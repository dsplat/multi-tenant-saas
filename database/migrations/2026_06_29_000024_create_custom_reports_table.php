<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自定义报表表
 *
 * 租户级自定义报表配置，支持选择指标 + 维度 + 时间范围，
 * 并按日报/周报/月报定时发送给指定接收人。
 *
 * 字段说明：
 * - metrics_config: 指标配置（JSON），如 {"metrics":["errors","costs"],"aggregation":"count"}
 * - dimensions:     维度配置（JSON），如 ["by_day","by_tenant"]
 * - time_range:     时间范围预设（last_7_days / last_30_days / last_month / custom）
 * - start_at/end_at: time_range=custom 时的自定义起止时间
 * - frequency:      发送频率（daily / weekly / monthly）
 * - recipients:      接收人列表（JSON，邮箱数组）
 * - format:          导出格式（csv / excel / pdf）
 * - template:        报表模板名（可选）
 * - status:          状态（draft / active / paused）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('custom_report_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->json('metrics_config')->nullable();
            $table->json('dimensions')->nullable();
            $table->string('time_range', 30)->default('last_7_days');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('frequency', 20)->default('daily');
            $table->json('recipients')->nullable();
            $table->string('format', 20)->default('csv');
            $table->string('template', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'frequency']);
            $table->index('next_send_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_reports');
    }
};
