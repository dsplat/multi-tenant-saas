<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_aliases', function (Blueprint $table) {
            $table->unsignedBigInteger('alias_id')->primary()->comment('别名ID（全局ID，16位数字）');
            $table->string('alias', 100)->comment('模型别名（友好名称）');
            $table->string('actual_model', 100)->comment('实际模型名（对应 AiModelEnum 值或自定义模型）');
            $table->string('provider', 50)->nullable()->comment('提供商标识（可选，用于约束/路由）');
            $table->string('type', 20)->comment('类型: text/image/video');
            $table->boolean('is_active')->default(true)->comment('是否激活');
            $table->boolean('is_deprecated')->default(false)->comment('废弃标记');
            $table->string('description', 255)->nullable()->comment('说明');
            $table->timestamps();

            $table->unique('alias', 'uk_alias');
            $table->index(['provider', 'type'], 'idx_provider_type');
            $table->index('is_active', 'idx_is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_aliases');
    }
};
