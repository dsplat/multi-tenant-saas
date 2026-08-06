<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付编排模块（Pay）
 * 表: sales_configs
 */
class PayModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('sales_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_config_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('mixed_pay_enabled')->default(false);
            $table->integer('points_to_cash_ratio')->default(100);
            $table->integer('max_points_deduct_ratio')->default(50);
            $table->timestamps();
            $table->unique(['tenant_id'], 'sales_configs_tenant_unique');
        });
    }

    public function getTableNames(): array
    {
        return ['sales_configs'];
    }
}
