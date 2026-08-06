<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logistics 模块：发货单
 *
 * shipments：物流登记（仅建模，不对接第三方快递 API）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipments')) {
            Schema::create('shipments', function (Blueprint $table) {
                $table->bigInteger('shipment_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('order_id')->unsigned();
                $table->string('order_no', 64);
                $table->bigInteger('user_id')->unsigned()->nullable();
                $table->string('carrier', 60)->nullable()->comment('承运方（快递公司名称）');
                $table->string('tracking_no', 64)->nullable()->comment('运单号');
                $table->string('status', 20)->default('pending')->comment('pending|shipped|delivered|cancelled');
                $table->string('receiver_name', 60)->nullable();
                $table->string('receiver_phone', 30)->nullable();
                $table->string('receiver_address', 500)->nullable();
                $table->json('items')->nullable()->comment('发货明细快照（sku_id/quantity）');
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
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
