<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();

        // SQLite 无 NOW() 函数，注册自定义函数以兼容源码中 DB::raw('NOW()') 的用法
        \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);

        $router = $this->app['router'];
        $router->aliasMiddleware('tenant.ensure', \MultiTenantSaas\Middleware\EnsureTenantContext::class);
        $router->aliasMiddleware('tenant.permission', \MultiTenantSaas\Middleware\CheckPermission::class);
        $router->aliasMiddleware('rbac.permission', \MultiTenantSaas\Middleware\CheckRbacPermission::class);

        // 加载 API 路由
        $router->prefix('api')->group(function () {
            require __DIR__.'/../routes/api.php';
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            TenancyServiceProvider::class,
        ];
    }

    protected function defineRoutes($router): void
    {
        $router->get('/api/v1/test', function () {
            return response()->json(['success' => true]);
        });
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.defaults.guard', 'sanctum');
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \MultiTenantSaas\Models\User::class,
        ]);
        
        // 设置 APP_KEY 用于加密
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUpDatabase(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigInteger('tenant_id')->unsigned()->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->nullable()->unique();
            $table->string('custom_domain', 200)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->string('subscription_plan', 50)->default('free');
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->timestamp('subscription_started_at')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('trial_extended')->default(false);
            $table->timestamp('trial_notification_sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->bigInteger('user_id')->unsigned()->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 20)->nullable()->unique();
            $table->string('role', 20)->default('platform_user');
            $table->string('avatar', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->bigInteger('tenant_user_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->string('role', 20)->default('end_user');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->integer('credits')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->bigInteger('setting_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('group', 50);
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'group', 'key']);
            $table->index('tenant_id');
        });

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
            $table->unsignedBigInteger('related_id')->nullable();
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

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigInteger('log_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('action', 50);
            $table->string('resource_type', 50);
            $table->bigInteger('resource_id')->unsigned()->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
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

        // RBAC 表
        Schema::create('permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id')->primary();
            $table->string('name', 100)->unique();
            $table->string('display_name', 200);
            $table->string('group', 50)->default('general');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->string('name', 50);
            $table->string('display_name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        // 通知表
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index('read_at');
        });

        // 订阅计划表
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
            $table->timestamps();
        });

        // 财务记录表
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

        // 文件上传表
        Schema::create('file_uploads', function (Blueprint $table) {
            $table->unsignedBigInteger('file_upload_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->string('disk', 20)->default('local');
            $table->string('path', 500);
            $table->string('filename', 255);
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('size')->unsigned()->default(0);
            $table->string('hash', 64)->nullable()->index();
            $table->string('category', 50)->default('general');
            $table->boolean('is_public')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ApiToken 模块表
        Schema::create('user_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->unsignedInteger('apisvr_token_id');
            $table->text('apisvr_key');
            $table->integer('remain_quota_cache')->default(0);
            $table->integer('used_quota_cache')->default(0);
            $table->timestamp('quota_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_api_token_histories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->unsignedInteger('apisvr_token_id');
            $table->text('masked_key');
            $table->string('action', 20)->default('created');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 通知偏好
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->unsignedBigInteger('notification_preference_id')->primary();
            $table->bigInteger('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('channel', 30);
            $table->string('type', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'channel', 'type'], 'notif_pref_unique');
        });

        // 订阅历史
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

        Schema::create('structured_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('category', 30);
            $table->string('action', 100);
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'category', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('metric', 100);
            $table->string('operator', 10)->default('>');
            $table->double('threshold')->default(0);
            $table->string('severity', 20)->default('warning');
            $table->json('channels')->nullable();
            $table->integer('cooldown_sec')->default(300);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
            $table->index('metric');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('rule_name', 100);
            $table->string('severity', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->index(['tenant_id', 'triggered_at']);
            $table->index(['rule_name', 'triggered_at']);
            $table->index('severity');
        });

        Schema::create('export_tasks', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('job_class');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_path', 500)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('user_id');
        });

        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('version', 30)->nullable();
            $table->string('status', 20)->default('installed');
            $table->json('manifest')->nullable();
            $table->json('config')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('plugin_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plugin_id');
            $table->string('dependency_name', 200);
            $table->string('version_constraint', 100)->nullable();
            $table->timestamps();

            $table->foreign('plugin_id')->references('id')->on('plugins')->onDelete('cascade');
            $table->index('dependency_name');
        });

        Schema::create('rate_limit_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('scope', 20)->default('user');
            $table->string('pattern', 200)->nullable();
            $table->unsignedInteger('max_attempts')->default(60);
            $table->unsignedInteger('decay_sec')->default(60);
            $table->string('strategy', 30)->default('fixed');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
            $table->index(['scope', 'enabled']);
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

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->unique();
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('api_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->string('status', 20)->default('stable');
            $table->date('release_date')->nullable();
            $table->date('sunset_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('oauth_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('oauth_account_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('provider', 50);
            $table->string('provider_id', 100);
            $table->string('provider_email')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('provider_avatar', 500)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider']);
            $table->index(['user_id', 'provider']);
            $table->unique(['provider', 'provider_id']);
        });

        // 用量记录表
        Schema::create('usage_records', function (Blueprint $table) {
            $table->unsignedBigInteger('usage_record_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('metric_type', 50);
            $table->decimal('value', 18, 4);
            $table->string('period', 7);
            $table->timestamp('recorded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'metric_type', 'period']);
        });

        // 邮件模板表（字段定义与 Migration 一致）
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('type', 50);
            $table->string('name_key', 50)->nullable();
            $table->string('name');
            $table->string('subject');
            $table->longText('html_body');
            $table->text('text_body')->nullable();
            $table->json('variables')->nullable();
            $table->string('status', 20)->default('activated');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['type', 'status']);
            $table->index(['name_key', 'tenant_id']);

            $table->foreign('tenant_id')
                ->references('tenant_id')
                ->on('tenants')
                ->onDelete('cascade');
        });

        // 优惠券表
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

        // 发票表
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

        // 税务规则表
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
    }

    protected function tearDown(): void
    {
        \MultiTenantSaas\Context\TenantContext::clear();
        parent::tearDown();
    }
}
