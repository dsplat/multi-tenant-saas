<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 统一商品交易体系 Phase 1：products 扩展 + product_skus 表
 *
 * - products.type：physical | virtual | course | event | points_goods
 * - products.sale_mode：cash | points | mixed（积分折现混合支付）
 * - product_skus：SKU 规格层，支持自建商品 SKU 与镜像 SKU
 *   （ref_type/ref_id 指向 event_ticket / points_product 等外部供给）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('type', 30)->default('physical')->comment('physical|virtual|course|event|points_goods')->after('status');
                $table->string('sale_mode', 20)->default('cash')->comment('cash|points|mixed')->after('type');
            });
        }

        if (! Schema::hasTable('product_skus')) {
            Schema::create('product_skus', function (Blueprint $table) {
                $table->bigInteger('sku_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('product_id')->unsigned()->nullable();
                $table->string('ref_type', 30)->nullable()->comment('镜像来源：event_ticket|points_product|course');
                $table->bigInteger('ref_id')->unsigned()->nullable();
                $table->string('name', 255);
                $table->json('spec_attrs')->nullable()->comment('规格属性快照');
                $table->decimal('price', 12, 2)->default(0)->comment('现金价（元）');
                $table->integer('points_price')->default(0)->comment('积分价（纯积分支付）');
                $table->integer('stock')->default(0)->comment('库存；镜像 SKU 以源表为准，0 表示不限');
                $table->integer('sold_count')->default(0);
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'product_id']);
                $table->index(['tenant_id', 'ref_type', 'ref_id'], 'product_skus_tenant_ref_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_skus');

        if (Schema::hasColumn('products', 'sale_mode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('sale_mode');
            });
        }
        if (Schema::hasColumn('products', 'type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
