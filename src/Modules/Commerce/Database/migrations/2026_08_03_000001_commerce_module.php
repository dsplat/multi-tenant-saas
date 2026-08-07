<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commerce 模块（Phase 1：消费类闭环）
 *
 * - commerce_skus: 平台商品表（无 tenant_id，平台级）
 * - commerce_orders / commerce_order_items: 租户购买订单（与 PaymentOrder 1:1）
 * - module_entitlements: 模块权益表（tenant_modules 保持纯开关语义）
 *
 * 设计依据: docs/commerce-sku.md、docs/commerce-module-plan.md
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commerce_skus')) {
            Schema::create('commerce_skus', function (Blueprint $table) {
                $table->unsignedBigInteger('sku_id')->primary()->comment('SKU ID（全局ID）');
                $table->string('name', 120)->comment('商品名称');
                $table->string('type', 30)->comment('plan|module|credit_pack|content_pack|mall_supply');
                $table->string('role', 20)->default('consumer')->comment('consumer|supply（第一级分类）');
                $table->string('lifecycle', 20)->default('one_time')->comment('subscription|one_time|consumable|grant');
                $table->string('fulfill_handler', 60)->comment('履约 Handler 标识');
                $table->decimal('price', 12, 2)->default(0)->comment('售价');
                $table->string('billing_cycle', 20)->nullable()->comment('monthly|yearly（订阅类）');
                $table->json('payload')->nullable()->comment('差异化参数（模块名/积分面额/套餐ID等）');
                $table->boolean('refundable')->default(false)->comment('是否可退款（积分包恒 false）');
                $table->string('status', 20)->default('draft')->comment('draft|active|retired');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index(['role', 'type', 'status'], 'idx_commerce_skus_role_type_status');
            });
        }

        if (! Schema::hasTable('commerce_orders')) {
            Schema::create('commerce_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('order_id')->primary()->comment('订单ID（全局ID）');
                $table->string('order_no', 64)->unique()->comment('订单号');
                $table->unsignedBigInteger('tenant_id')->comment('租户ID');
                $table->decimal('amount', 12, 2)->default(0)->comment('订单金额');
                $table->string('status', 20)->default('pending')->comment('pending|paid|fulfilled|partial_failed|cancelled|refunded');
                $table->unsignedBigInteger('payment_order_id')->nullable()->comment('关联支付单（PaymentOrder，1:1）');
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('operator_id')->nullable()->comment('下单 Operator');
                $table->timestamps();
                $table->index(['tenant_id', 'status'], 'idx_commerce_orders_tenant_status');
            });
        }

        if (! Schema::hasTable('commerce_order_items')) {
            Schema::create('commerce_order_items', function (Blueprint $table) {
                $table->unsignedBigInteger('item_id')->primary()->comment('订单项ID（全局ID）');
                $table->unsignedBigInteger('order_id')->comment('所属订单');
                $table->unsignedBigInteger('sku_id')->comment('SKU 引用');
                $table->integer('qty')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->string('fulfill_status', 20)->default('pending')->comment('pending|fulfilled|failed|revoked');
                $table->timestamp('fulfill_at')->nullable();
                $table->integer('retry_count')->default(0);
                $table->string('fail_reason', 500)->nullable();
                $table->json('payload_snapshot')->nullable()->comment('下单时 SKU payload 快照');
                $table->timestamps();
                $table->index('order_id', 'idx_commerce_items_order');
                $table->index(['fulfill_status', 'retry_count'], 'idx_commerce_items_fulfill');
            });
        }

        if (! Schema::hasTable('module_entitlements')) {
            Schema::create('module_entitlements', function (Blueprint $table) {
                $table->unsignedBigInteger('entitlement_id')->primary()->comment('权益ID（全局ID）');
                $table->unsignedBigInteger('tenant_id')->comment('租户ID');
                $table->string('module_name', 60)->comment('模块标识');
                $table->string('source', 20)->default('purchase')->comment('plan|purchase|system');
                $table->unsignedBigInteger('source_order_id')->nullable()->comment('来源订单');
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable()->comment('NULL=永久（买断）');
                $table->string('status', 20)->default('active')->comment('active|expired|revoked');
                $table->timestamps();
                $table->index(['tenant_id', 'module_name', 'status'], 'idx_module_entitlements_lookup');
            });
        }

        // supply_grants: 供给授权（内容分销/积分商城 SKU 共用）
        if (! Schema::hasTable('supply_grants')) {
            Schema::create('supply_grants', function (Blueprint $table) {
                $table->unsignedBigInteger('grant_id')->primary()->comment('授权ID（全局ID）');
                $table->unsignedBigInteger('tenant_id')->comment('获授权租户');
                $table->unsignedBigInteger('sku_id')->comment('供给 SKU 引用');
                $table->unsignedBigInteger('source_order_id')->nullable()->comment('来源订单');
                $table->string('status', 20)->default('active')->comment('active|suspended|expired|revoked');
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable()->comment('NULL=永久');
                $table->json('settlement')->nullable()->comment('结算参数（供货价/分成比例/模式）');
                $table->json('instance_payload')->nullable()->comment('履约产物引用（content_id / points_product_id 等）');
                $table->timestamps();
                $table->index(['tenant_id', 'status'], 'idx_supply_grants_tenant_status');
                $table->index(['sku_id', 'status'], 'idx_supply_grants_sku_status');
            });
        }

        // platform_contents: 平台内容条目
        if (! Schema::hasTable('platform_contents')) {
            Schema::create('platform_contents', function (Blueprint $table) {
                $table->unsignedBigInteger('content_id')->primary()->comment('内容ID（全局ID）');
                $table->string('title', 200)->comment('标题');
                $table->string('type', 30)->default('article')->comment('article|video|audio|image|file');
                $table->text('body')->nullable()->comment('正文（富文本/纯文本）');
                $table->string('file_url', 500)->nullable()->comment('媒体文件地址');
                $table->string('cover_url', 500)->nullable()->comment('封面');
                $table->json('tags')->nullable()->comment('标签');
                $table->string('status', 20)->default('draft')->comment('draft|published|retired');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index(['status', 'type']);
            });
        }

        // platform_content_packs: 内容包
        if (! Schema::hasTable('platform_content_packs')) {
            Schema::create('platform_content_packs', function (Blueprint $table) {
                $table->unsignedBigInteger('pack_id')->primary()->comment('内容包ID（全局ID）');
                $table->string('name', 200)->comment('包名');
                $table->string('description', 500)->nullable();
                $table->string('cover_url', 500)->nullable();
                $table->string('status', 20)->default('draft')->comment('draft|active|retired');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index('status');
            });
        }

        // platform_content_pack_items: 包-内容关联
        if (! Schema::hasTable('platform_content_pack_items')) {
            Schema::create('platform_content_pack_items', function (Blueprint $table) {
                $table->unsignedBigInteger('pack_id');
                $table->unsignedBigInteger('content_id');
                $table->integer('sort_order')->default(0);
                $table->primary(['pack_id', 'content_id']);
                $table->index('content_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_content_pack_items');
        Schema::dropIfExists('platform_content_packs');
        Schema::dropIfExists('platform_contents');
        Schema::dropIfExists('supply_grants');
        Schema::dropIfExists('module_entitlements');
        Schema::dropIfExists('commerce_order_items');
        Schema::dropIfExists('commerce_orders');
        Schema::dropIfExists('commerce_skus');
    }
};
