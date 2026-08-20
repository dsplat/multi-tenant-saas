<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品模块（Product）
 * 表: products, product_categories, product_skus, package_items
 */
class ProductModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('market_price', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('type', 30)->default('physical');
            $table->string('sale_mode', 20)->default('cash');
            $table->json('price_strategy')->nullable();
            $table->json('media_assets')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('product_category_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 255);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::create('product_skus', function (Blueprint $table) {
            $table->unsignedBigInteger('sku_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('ref_type', 30)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('name', 255);
            $table->json('spec_attrs')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('points_price')->default(0);
            $table->integer('stock')->default(0);
            $table->integer('sold_count')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'ref_type', 'ref_id'], 'product_skus_tenant_ref_index');
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->unsignedBigInteger('package_item_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('package_id');
            $table->string('item_type', 50);
            $table->string('item_id', 64);
            $table->unsignedBigInteger('sku_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'package_id', 'item_type', 'item_id'], 'package_items_unique');
            $table->index(['tenant_id', 'package_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['products', 'product_categories', 'product_skus', 'package_items'];
    }
}
