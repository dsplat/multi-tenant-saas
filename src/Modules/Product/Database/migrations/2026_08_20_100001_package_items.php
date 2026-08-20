<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package 组合实体组成表
 *
 * package 本身是 products.type='package' 的商品；本表记录其组成项
 * （多态实体引用 item_type/item_id，sku_id 可选锁定具体规格）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_items', function (Blueprint $table) {
            $table->bigInteger('package_item_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('package_id')->unsigned()->comment('所属 package（products.product_id，type=package）');
            $table->string('item_type', 50)->comment('组成实体类型（EntityTypes 白名单）');
            $table->string('item_id', 64)->comment('组成实体 ID');
            $table->bigInteger('sku_id')->unsigned()->nullable()->comment('可选：锁定具体规格');
            $table->integer('quantity')->default(1);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'package_id', 'item_type', 'item_id'], 'package_items_unique');
            $table->index(['tenant_id', 'package_id'], 'package_items_pkg_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
    }
};
