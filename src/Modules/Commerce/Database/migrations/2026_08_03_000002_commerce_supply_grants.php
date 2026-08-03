<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commerce 模块（Phase 2：供给类）
 *
 * - supply_grants: 供给授权（内容分销/积分商城 SKU 共用，已决策）
 *   租户购入 supply SKU 后获得的「代理证」，承载结算参数与履约产物引用。
 *
 * 设计依据: docs/commerce-sku.md、docs/commerce-module-plan.md 3.3
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supply_grants')) {
            Schema::create('supply_grants', function (Blueprint $table) {
                $table->unsignedBigInteger('grant_id')->primary()->comment('授权ID（全局ID）');
                $table->unsignedBigInteger('tenant_id')->comment('获授权租户');
                $table->unsignedBigInteger('sku_id')->comment('供给 SKU 引用');
                $table->unsignedBigInteger('source_order_id')->nullable()->comment('来源订单');
                $table->string('status', 20)->default('active')->comment('active|suspended|expired|revoked');
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable()->comment('NULL=永久');
                $table->json('settlement')->nullable()->comment('结算参数（供货价/分成比例/模式）');
                $table->json('instance_payload')->nullable()->comment('履约产物引用（content_id / points_product_id 等）');
                $table->timestamps();
                $table->index(['tenant_id', 'status'], 'idx_supply_grants_tenant_status');
                $table->index(['sku_id', 'status'], 'idx_supply_grants_sku_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_grants');
    }
};
