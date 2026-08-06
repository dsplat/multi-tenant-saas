<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 统一商品交易体系 Phase 2：统一订单中心
 *
 * - orders：一切交易皆订单（registration | product | course | exchange）
 * - order_items：订单商品行（SKU/课程/票种/积分商品快照）
 * - consumption_records：消费流水（现金 + 积分分轨记录）
 *
 * 注：sales_configs（销售折现配置）已拆入 Pay 模块迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->bigInteger('order_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('user_id')->unsigned()->nullable()->comment('交易主体一律 user_id');
                $table->string('order_no', 64)->unique();
                $table->string('order_type', 30)->default('product')->comment('registration|product|course|exchange');
                $table->decimal('total_amount', 12, 2)->default(0)->comment('现金部分（元），计佣基数');
                $table->integer('points_amount')->default(0)->comment('积分部分');
                $table->string('pay_method', 20)->default('cash')->comment('cash|points|mixed');
                $table->string('status', 20)->default('pending')->comment('pending|paid|refunded|refund_failed|cancelled');
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->bigInteger('payment_order_id')->unsigned()->nullable()->comment('框架支付订单关联');
                $table->json('source')->nullable()->comment('渠道/分销归因');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'user_id']);
                $table->index(['tenant_id', 'order_type']);
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->bigInteger('item_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('order_id')->unsigned();
                $table->bigInteger('sku_id')->unsigned()->nullable();
                $table->bigInteger('product_id')->unsigned()->nullable();
                $table->string('item_type', 30)->default('sku')->comment('sku|course|ticket|points_product');
                $table->bigInteger('ref_id')->unsigned()->nullable()->comment('外部供给 ID（课程/票种/积分商品）');
                $table->string('item_name', 255);
                $table->json('spec')->nullable()->comment('规格快照');
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0)->comment('现金单价（元）');
                $table->integer('points_unit_price')->default(0)->comment('积分单价');
                $table->decimal('amount', 12, 2)->default(0)->comment('现金小计（元）');
                $table->timestamps();
                $table->index(['tenant_id', 'order_id']);
            });
        }

        if (! Schema::hasTable('consumption_records')) {
            Schema::create('consumption_records', function (Blueprint $table) {
                $table->bigInteger('record_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->bigInteger('order_id')->unsigned();
                $table->string('order_type', 30)->default('product');
                $table->decimal('cash_amount', 12, 2)->default(0);
                $table->integer('points_amount')->default(0);
                $table->timestamp('consumed_at');
                $table->timestamps();
                $table->unique(['tenant_id', 'order_id'], 'consumption_records_tenant_order_unique');
                $table->index(['tenant_id', 'consumed_at']);
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consumption_records');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
