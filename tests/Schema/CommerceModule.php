<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商业体模块（Commerce）
 * 表: commerce_skus, commerce_orders, commerce_order_items, module_entitlements, supply_grants,
 *     platform_contents, platform_content_packs, platform_content_pack_items
 */
class CommerceModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('commerce_skus', function (Blueprint $table) {
            $table->unsignedBigInteger('sku_id')->primary();
            $table->string('name', 120);
            $table->string('type', 30);
            $table->string('role', 20)->default('consumer');
            $table->string('lifecycle', 20)->default('one_time');
            $table->string('fulfill_handler', 60);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('billing_cycle', 20)->nullable();
            $table->json('payload')->nullable();
            $table->boolean('refundable')->default(false);
            $table->string('status', 20)->default('draft');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['role', 'type', 'status']);
        });

        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->string('order_no', 64)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('payment_order_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->primary();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('sku_id');
            $table->integer('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('fulfill_status', 20)->default('pending');
            $table->timestamp('fulfill_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->string('fail_reason', 500)->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->timestamps();
            $table->index('order_id');
            $table->index(['fulfill_status', 'retry_count']);
        });

        Schema::create('module_entitlements', function (Blueprint $table) {
            $table->unsignedBigInteger('entitlement_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('module_name', 60);
            $table->string('source', 20)->default('purchase');
            $table->unsignedBigInteger('source_order_id')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['tenant_id', 'module_name', 'status']);
        });

        Schema::create('supply_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('grant_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sku_id');
            $table->unsignedBigInteger('source_order_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('settlement')->nullable();
            $table->unsignedInteger('allocated_qty')->default(0);
            $table->unsignedInteger('remaining_qty')->default(0);
            $table->unsignedInteger('locked_qty')->default(0);
            $table->json('instance_payload')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['sku_id', 'status']);
        });

        Schema::create('platform_contents', function (Blueprint $table) {
            $table->unsignedBigInteger('content_id')->primary();
            $table->string('title', 200);
            $table->string('type', 30)->default('article');
            $table->text('body')->nullable();
            $table->string('file_url', 500)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['status', 'type']);
        });

        Schema::create('platform_content_packs', function (Blueprint $table) {
            $table->unsignedBigInteger('pack_id')->primary();
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('platform_content_pack_items', function (Blueprint $table) {
            $table->unsignedBigInteger('pack_id');
            $table->unsignedBigInteger('content_id');
            $table->integer('sort_order')->default(0);
            $table->primary(['pack_id', 'content_id']);
            $table->index('content_id');
        });
    }

    public function getTableNames(): array
    {
        return [
            'commerce_order_items', 'commerce_orders', 'commerce_skus', 'module_entitlements', 'supply_grants',
            'platform_content_pack_items', 'platform_content_packs', 'platform_contents',
        ];
    }
}
