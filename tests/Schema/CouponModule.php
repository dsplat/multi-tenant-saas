<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 优惠券模块
 * 表: coupons, coupon_usages, coupon_shares
 */
class CouponModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigInteger('coupon_id')->unsigned()->primary();
            $table->string('code', 32)->unique();
            $table->string('description', 512)->nullable();
            $table->string('type', 20)->default('fixed');
            $table->decimal('value', 12, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->decimal('min_amount', 12, 2)->nullable();
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->string('applies_to', 20)->default('subscription');
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->unsignedInteger('duration_months')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_tenant')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_template')->default(false);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('code');
            $table->index(['is_active', 'is_template']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->decimal('discount_amount', 12, 2);
            $table->string('currency', 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'tenant_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('coupon_shares', function (Blueprint $table) {
            $table->bigInteger('share_id')->unsigned()->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sharer_id');
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->unsignedBigInteger('coupon_template_id');
            $table->string('share_code', 32)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('share_code');
        });
    }

    public function getTableNames(): array
    {
        return ['coupons', 'coupon_usages', 'coupon_shares'];
    }
}
