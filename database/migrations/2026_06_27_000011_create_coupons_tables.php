<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id')->primary();
            $table->string('code', 64)->unique()->comment('优惠券码');
            $table->string('description')->nullable()->comment('描述');
            $table->string('type', 20)->default('fixed')->comment('类型: fixed=固定金额 percentage=百分比');
            $table->decimal('value', 12, 2)->default(0)->comment('折扣值: 固定金额或百分比(0-100)');
            $table->string('currency', 8)->nullable()->comment('币种，固定金额时使用');
            $table->decimal('min_amount', 12, 2)->nullable()->comment('最低消费金额');
            $table->decimal('max_discount', 12, 2)->nullable()->comment('百分比折扣上限');
            $table->string('applies_to', 20)->default('subscription')->comment('适用范围: subscription/invoice/all');
            $table->unsignedBigInteger('subscription_plan_id')->nullable()->comment('限定订阅计划');
            $table->unsignedSmallInteger('duration_months')->nullable()->comment('订阅抵扣持续月数');
            $table->unsignedInteger('max_uses')->nullable()->comment('最大使用次数，null=不限');
            $table->unsignedSmallInteger('max_uses_per_tenant')->default(1)->comment('每租户最大使用次数');
            $table->unsignedInteger('used_count')->default(0)->comment('已使用次数');
            $table->timestamp('starts_at')->nullable()->comment('生效时间');
            $table->timestamp('expires_at')->nullable()->comment('过期时间');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->index('subscription_plan_id');
            $table->index('is_active');
            $table->index('expires_at');
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_usage_id')->primary();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->comment('兑换用户');
            $table->unsignedBigInteger('invoice_id')->nullable()->comment('关联发票');
            $table->unsignedBigInteger('subscription_plan_id')->nullable()->comment('关联订阅计划');
            $table->decimal('discount_amount', 12, 2)->default(0)->comment('实际抵扣金额');
            $table->string('currency', 8)->nullable()->comment('币种');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->foreign('coupon_id')->references('coupon_id')->on('coupons')->onDelete('cascade');
            $table->index(['coupon_id', 'tenant_id']);
            $table->index('user_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
    }
};
