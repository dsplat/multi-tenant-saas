<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_usage_quotas 表迁移
 *
 * 租户级 AI 用量配额表：按计费周期（period，如 monthly:2026-06）记录租户的
 * Token 用量、图片生成次数与视频生成时长，以及对应的套餐配额上限。
 * 用量数据由 AiUsageService 实时累加，并作为超额判断依据。
 * 每个租户每个周期一行（tenant_id + period 唯一）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_quotas', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_usage_quota_id')->primary()->comment('配额ID（全局ID，16位数字）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('subscription_plan_id')->nullable()->comment('套餐ID');
            $table->unsignedBigInteger('text_token_limit')->default(0)->comment('文本 Token 月度上限（0 表示不限）');
            $table->unsignedBigInteger('image_generation_limit')->default(0)->comment('图片生成月度上限（0 表示不限）');
            $table->unsignedBigInteger('video_duration_limit')->default(0)->comment('视频时长月度上限（秒，0 表示不限）');
            $table->string('period', 20)->default('monthly')->comment('计费周期标识，如 monthly:2026-06');
            $table->unsignedBigInteger('used_tokens')->default(0)->comment('已用 Token 数');
            $table->unsignedBigInteger('used_images')->default(0)->comment('已生成图片数');
            $table->unsignedBigInteger('used_video_seconds')->default(0)->comment('已生成视频时长（秒）');
            $table->timestamps();

            $table->unique(['tenant_id', 'period'], 'uniq_tenant_period');
            $table->index(['tenant_id', 'period']);
            $table->index('subscription_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_quotas');
    }
};
