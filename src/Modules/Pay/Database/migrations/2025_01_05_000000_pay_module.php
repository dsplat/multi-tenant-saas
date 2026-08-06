<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay 模块：租户级销售折现配置
 *
 * sales_configs 原属订单迁移，模块化后拆入 Pay（混合支付开关/积分折现比例/最高抵扣比例）。
 * 幂等：scrm 等既有项目表已存在（历史迁移接管），hasTable 守卫跳过。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_configs')) {
            Schema::create('sales_configs', function (Blueprint $table) {
                $table->bigInteger('sales_config_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->boolean('mixed_pay_enabled')->default(false)->comment('积分折现混合支付开关');
                $table->integer('points_to_cash_ratio')->default(100)->comment('N 积分 = 1 元');
                $table->integer('max_points_deduct_ratio')->default(50)->comment('积分最高抵扣比例（%）');
                $table->timestamps();
                $table->unique(['tenant_id'], 'sales_configs_tenant_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_configs');
    }
};
