<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->primary();
            $table->string('name')->comment('模板名称');
            $table->string('description')->nullable()->comment('模板描述');
            $table->string('type', 20)->default('fixed')->comment('类型: fixed/percentage');
            $table->decimal('value', 12, 2)->default(0)->comment('折扣值');
            $table->string('currency', 8)->nullable()->comment('币种');
            $table->decimal('min_amount', 12, 2)->nullable()->comment('最低消费金额');
            $table->decimal('max_discount', 12, 2)->nullable()->comment('百分比折扣上限');
            $table->string('applies_to', 20)->default('subscription')->comment('适用范围');
            $table->unsignedBigInteger('subscription_plan_id')->nullable()->comment('限定订阅计划');
            $table->unsignedSmallInteger('duration_months')->nullable()->comment('订阅抵扣持续月数');
            $table->unsignedInteger('max_uses')->nullable()->comment('最大使用次数');
            $table->unsignedSmallInteger('max_uses_per_tenant')->default(1)->comment('每租户最大使用次数');
            $table->unsignedSmallInteger('valid_days')->default(30)->comment('有效期天数');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });

        Schema::create('coupon_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('rule_id')->primary();
            $table->unsignedBigInteger('coupon_id')->comment('关联优惠券');
            $table->string('rule_type', 50)->comment('规则类型: stackable/category_limit/tiered_threshold');
            $table->json('rule_config')->comment('规则配置');
            $table->unsignedSmallInteger('priority')->default(0)->comment('优先级');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->string('description')->nullable()->comment('规则描述');
            $table->timestamps();

            $table->foreign('coupon_id')->references('coupon_id')->on('coupons')->onDelete('cascade');
            $table->index('coupon_id');
            $table->index('rule_type');
        });

        Schema::create('coupon_distributions', function (Blueprint $table) {
            $table->unsignedBigInteger('distribution_id')->primary();
            $table->unsignedBigInteger('coupon_id')->comment('发放的优惠券');
            $table->unsignedBigInteger('template_id')->nullable()->comment('发放模板');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('接收租户');
            $table->unsignedBigInteger('user_id')->nullable()->comment('接收用户');
            $table->string('distribution_type', 30)->default('batch')->comment('发放类型: batch/split/invite');
            $table->unsignedBigInteger('source_user_id')->nullable()->comment('裂变来源用户');
            $table->string('batch_id', 64)->nullable()->comment('批次ID');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->foreign('coupon_id')->references('coupon_id')->on('coupons')->onDelete('cascade');
            $table->index('coupon_id');
            $table->index('template_id');
            $table->index('tenant_id');
            $table->index('batch_id');
        });

        Schema::create('sms_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户 ID');
            $table->string('name')->comment('模板名称');
            $table->string('code', 64)->unique()->comment('模板编码');
            $table->text('content')->comment('模板内容');
            $table->string('type', 20)->default('marketing')->comment('类型: marketing/notification/verification');
            $table->string('sign_name', 20)->nullable()->comment('短信签名');
            $table->json('params')->nullable()->comment('模板参数定义');
            $table->string('status', 20)->default('active')->comment('状态: active/inactive/auditing');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('type');
            $table->index('status');
        });

        Schema::create('sms_batch_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户 ID');
            $table->unsignedBigInteger('template_id')->nullable()->comment('短信模板');
            $table->string('name')->comment('任务名称');
            $table->string('status', 20)->default('pending')->comment('状态: pending/sending/completed/failed');
            $table->unsignedInteger('total_count')->default(0)->comment('总数');
            $table->unsignedInteger('sent_count')->default(0)->comment('已发送');
            $table->unsignedInteger('success_count')->default(0)->comment('成功数');
            $table->unsignedInteger('fail_count')->default(0)->comment('失败数');
            $table->timestamp('scheduled_at')->nullable()->comment('计划发送时间');
            $table->timestamp('started_at')->nullable()->comment('开始发送时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('scheduled_at');
        });

        Schema::create('sms_send_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->unsignedBigInteger('task_id')->nullable()->comment('批次任务');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户 ID');
            $table->string('phone', 20)->comment('手机号');
            $table->text('content')->comment('短信内容');
            $table->unsignedBigInteger('template_id')->nullable()->comment('使用的模板');
            $table->string('status', 20)->default('pending')->comment('状态: pending/sent/delivered/failed');
            $table->string('provider', 20)->comment('发送渠道');
            $table->json('provider_response')->nullable()->comment('渠道响应');
            $table->string('error_message')->nullable()->comment('错误信息');
            $table->timestamp('sent_at')->nullable()->comment('发送时间');
            $table->timestamp('delivered_at')->nullable()->comment('送达时间');
            $table->timestamps();

            $table->index('task_id');
            $table->index('tenant_id');
            $table->index('phone');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_send_logs');
        Schema::dropIfExists('sms_batch_tasks');
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('coupon_distributions');
        Schema::dropIfExists('coupon_rules');
        Schema::dropIfExists('coupon_templates');
    }
};