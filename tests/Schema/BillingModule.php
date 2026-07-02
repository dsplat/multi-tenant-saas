<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 计费模块
 * 表: credit_accounts, credit_transactions, payment_orders, financial_records,
 *     invoices, invoice_items, tax_rules, coupons, coupon_usages,
 *     subscription_plans, subscription_histories, user_payment_passwords, payment_logs
 */
class BillingModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('credit_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_account_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('account_type', ['enterprise', 'personal'])->default('personal');
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('gift_balance')->default(0);
            $table->unsignedBigInteger('recharge_balance')->default(0);
            $table->unsignedBigInteger('total_recharged')->default(0);
            $table->unsignedBigInteger('total_consumed')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->integer('expired_total')->default(0);
            $table->timestamp('last_warning_at')->nullable();
            $table->boolean('auto_recharge_enabled')->default(false);
            $table->integer('auto_recharge_threshold')->default(100);
            $table->integer('auto_recharge_amount')->default(1000);
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->timestamps();
            $table->index('tenant_id');
            $table->index('user_id');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_id')->primary();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('type', ['recharge', 'consume', 'refund', 'transfer', 'gift', 'expire']);
            $table->bigInteger('amount');
            $table->unsignedBigInteger('balance_after')->default(0);
            $table->string('related_type', 100)->nullable();
            $table->string('related_id', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('expired')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });

        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->string('order_no', 64)->unique();
            $table->string('driver', 20)->default('wechat');
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('transaction_id')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('financial_records', function (Blueprint $table) {
            $table->bigInteger('financial_record_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('type', 30);
            $table->integer('amount')->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_order_no', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->string('invoice_number')->unique();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('draft');
            $table->dateTime('issued_at')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('payment_order_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('issued_at');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_item_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->unsignedBigInteger('invoice_id');
            $table->string('description');
            $table->decimal('quantity', 8, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_rate', 5, 4);
            $table->decimal('tax_amount', 12, 2);
            $table->nullableMorphs('related');
            $table->timestamps();

            $table->index('invoice_id');
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_rule_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->string('region_code', 10);
            $table->decimal('tax_rate', 5, 4);
            $table->string('tax_name');
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('region_code');
            $table->index(['region_code', 'is_default']);
            $table->index('effective_date');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id')->primary();
            $table->string('code', 64)->unique();
            $table->string('description')->nullable();
            $table->string('type', 20)->default('fixed');
            $table->decimal('value', 12, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->decimal('min_amount', 12, 2)->nullable();
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->string('applies_to', 20)->default('subscription');
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedSmallInteger('max_uses_per_tenant')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('subscription_plan_id');
            $table->index('is_active');
            $table->index('expires_at');
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_usage_id')->primary();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('coupon_id')->references('coupon_id')->on('coupons')->onDelete('cascade');
            $table->index(['coupon_id', 'tenant_id']);
            $table->index('user_id');
            $table->index('invoice_id');
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_plan_id')->primary();
            $table->string('name', 50)->unique();
            $table->string('display_name', 200);
            $table->text('description')->nullable();
            $table->integer('price_monthly')->default(0);
            $table->integer('price_yearly')->default(0);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metered_price')->nullable();
            $table->string('metered_unit', 30)->nullable();
            $table->boolean('overage_allowed')->default(false);
            $table->decimal('overage_price', 10, 4)->default(0);
            $table->unsignedInteger('rate_limit_rpm')->default(60);
            $table->unsignedBigInteger('ai_text_tokens')->default(0);
            $table->unsignedBigInteger('ai_image_generations')->default(0);
            $table->unsignedBigInteger('ai_video_seconds')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_history_id')->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('action', 30);
            $table->string('from_plan', 50)->nullable();
            $table->string('to_plan', 50)->nullable();
            $table->string('billing_cycle', 20)->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('proration_amount', 10, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('user_payment_passwords', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->string('password_hash');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('order_no', 64)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 20);
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('order_no');
        });
    }

    public function getTableNames(): array
    {
        return [
            'credit_accounts', 'credit_transactions', 'payment_orders', 'financial_records',
            'invoices', 'invoice_items', 'tax_rules', 'coupons', 'coupon_usages',
            'subscription_plans', 'subscription_histories', 'user_payment_passwords', 'payment_logs',
        ];
    }
}
