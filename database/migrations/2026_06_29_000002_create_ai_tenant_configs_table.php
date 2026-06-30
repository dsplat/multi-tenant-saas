<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_tenant_configs 表迁移
 *
 * 租户级 AI 配置表：存储租户对文本/图片/视频 AI 能力的开关、自定义 API Key、
 * 允许使用的模型列表、月度预算上限与超额处理策略（block/warn/allow）。
 * 每个租户一行（tenant_id 唯一）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tenant_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_tenant_config_id')->primary()->comment('配置ID（全局ID，16位数字）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->boolean('text_enabled')->default(true)->comment('是否启用文本 AI');
            $table->boolean('image_enabled')->default(true)->comment('是否启用图片 AI');
            $table->boolean('video_enabled')->default(true)->comment('是否启用视频 AI');
            $table->json('custom_api_keys')->nullable()->comment('自定义 API Key：{provider: key}，覆盖系统默认');
            $table->json('allowed_models')->nullable()->comment('允许租户使用的模型列表，null 表示继承系统默认');
            $table->decimal('monthly_budget_limit', 12, 2)->default(0)->comment('月度预算上限（0 表示不限）');
            $table->string('overage_action', 20)->default('block')->comment('超额处理: block/warn/allow');
            $table->timestamps();

            $table->unique('tenant_id', 'uniq_tenant');
            $table->index('text_enabled');
            $table->index('image_enabled');
            $table->index('video_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tenant_configs');
    }
};
