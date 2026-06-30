<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 成本分摊表
 *
 * 记录租户级成本分摊数据，按月聚合。包含：
 * - 基础设施成本（计算/存储/带宽）
 * - AI 用量成本（由 AiUsageService 联动归入）
 * - 第三方服务成本
 *
 * 分摊依据（allocation_basis）描述成本分摊的规则，如 by_users / by_storage / by_requests；
 * allocation_value 记录分摊依据的量化值（如用户数、存储量、请求数）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_allocation_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable(); // 租户 ID（NULL 为系统级成本）
            $table->string('cost_type', 30); // 成本类型：infrastructure / ai_usage / third_party
            $table->string('cost_subtype', 50)->nullable(); // 子类型：compute / storage / bandwidth / 服务名
            $table->decimal('amount', 14, 4)->default(0); // 金额
            $table->string('currency', 10)->default('CNY'); // 币种
            $table->string('period', 7); // 计费周期（YYYY-MM）
            $table->string('allocation_basis', 100)->nullable(); // 分摊依据
            $table->decimal('allocation_value', 14, 4)->nullable(); // 分摊依据量化值
            $table->json('metadata')->nullable(); // 附加元数据
            $table->timestamps();

            $table->index(['tenant_id', 'period', 'cost_type']);
            $table->index(['period', 'cost_type']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_allocations');
    }
};
