<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 数据保留策略表（GDPR 合规）
 *
 * 定义各数据类型的保留期限和清理策略。
 * 支持系统级（tenant_id 为 null）和租户级策略。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->unsignedBigInteger('data_retention_policy_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('data_type', 50);
            $table->unsignedInteger('retention_days')->default(365);
            $table->boolean('auto_cleanup')->default(false);
            $table->string('cleanup_strategy', 20)->default('delete');
            $table->boolean('is_exempt')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'data_type'], 'uniq_retention_tenant_type');
            $table->index(['auto_cleanup', 'is_exempt']);
            $table->index('data_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_policies');
    }
};
