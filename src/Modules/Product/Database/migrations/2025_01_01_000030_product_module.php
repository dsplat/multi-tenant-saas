<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product 模块：商品目录基础表
 *
 * - products：一切皆商品（type: physical|virtual|course|event|points_goods）
 * - product_categories：商品分类
 *
 * 幂等：scrm 等既有项目可能已存在同名表（历史迁移接管），hasTable 守卫跳过。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('market_price', 12, 2)->nullable();
                $table->integer('stock')->default(0);
                $table->string('status', 20)->default('draft')->comment('draft|active|inactive');
                $table->string('type', 30)->default('physical')->comment('physical|virtual|course|event|points_goods');
                $table->string('sale_mode', 20)->default('cash')->comment('cash|points|mixed');
                $table->json('price_strategy')->nullable();
                $table->json('media_assets')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'type']);
            });
        }

        if (! Schema::hasTable('product_categories')) {
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
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('products');
    }
};
