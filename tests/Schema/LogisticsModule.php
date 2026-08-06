<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物流模块（Logistics）
 * 表: shipments
 */
class LogisticsModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->string('order_no', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('carrier', 60)->nullable();
            $table->string('tracking_no', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('receiver_name', 60)->nullable();
            $table->string('receiver_phone', 30)->nullable();
            $table->string('receiver_address', 500)->nullable();
            $table->json('items')->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'tracking_no'], 'shipments_tenant_tracking_index');
        });
    }

    public function getTableNames(): array
    {
        return ['shipments'];
    }
}
