<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->primary()->comment('提供商ID（全局ID，16位数字）');
            $table->bigInteger('tenant_id')->unsigned()->nullable()->comment('租户ID，null 表示系统级配置');
            $table->string('code', 50)->comment('提供商标识（openai/zhipu/anthropic 等），对应 config(ai.providers) 键名');
            $table->string('name', 100)->comment('提供商显示名称');
            $table->string('base_url', 255)->nullable()->comment('API 基地址');
            $table->text('api_key')->nullable()->comment('默认 API Key（加密存储）');
            $table->string('status', 20)->default('active')->comment('状态: active/inactive');
            $table->smallInteger('priority')->default(0)->comment('优先级，数字越小越优先');
            $table->json('metadata')->nullable()->comment('扩展配置（超时、额外参数等）');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'uk_tenant_code');
            $table->index('status', 'idx_status');
            $table->index('priority', 'idx_priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
