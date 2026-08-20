<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单模块（Order）
 * 表: orders, order_items, consumption_records
 */
class OrderModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('order_no', 64)->unique();
            $table->string('order_type', 30)->default('product');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('points_amount')->default(0);
            $table->string('pay_method', 20)->default('cash');
            $table->string('entity_type', 50)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedBigInteger('payment_order_id')->nullable();
            $table->json('source')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'order_type']);
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'orders_entity_idx');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('sku_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name', 255);
            $table->json('spec')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->integer('points_unit_price')->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('consumption_records', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id');
            $table->string('order_type', 30)->default('product');
            $table->decimal('cash_amount', 12, 2)->default(0);
            $table->integer('points_amount')->default(0);
            $table->timestamp('consumed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'order_id'], 'consumption_records_tenant_order_unique');
            $table->index(['tenant_id', 'consumed_at']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('order_entity_relations', function (Blueprint $table) {
            $table->unsignedBigInteger('relation_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->string('entity_type', 50);
            $table->string('entity_id', 64);
            $table->string('relation_type', 30);
            $table->decimal('share_amount', 10, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'order_id'], 'oer_order_idx');
        });
    }

    public function getTableNames(): array
    {
        return ['orders', 'order_items', 'consumption_records', 'order_entity_relations'];
    }
}
