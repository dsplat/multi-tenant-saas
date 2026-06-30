<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 功能开关表
 *
 * 存储全局/租户/用户级功能开关定义，支持灰度发布（百分比滚动）、
 * A/B 测试分组与开关依赖关系。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->unsignedBigInteger('feature_flag_id')->primary();
            $table->string('name', 100)->unique(); // 开关名称（唯一 key）
            $table->string('description', 255)->nullable(); // 描述
            $table->string('scope', 20)->default('global'); // 范围：global / tenant / user
            $table->json('conditions')->nullable(); // 启用条件：A/B 分组、租户/用户覆盖
            $table->json('dependencies')->nullable(); // 依赖的其他开关名列表
            $table->unsignedTinyInteger('rollout_percentage')->default(0); // 灰度比例 0-100
            $table->string('status', 20)->default('inactive'); // 状态：active / inactive
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
