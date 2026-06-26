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
        
        // 加载 API 路由
        $this->app['router']->prefix('api')->group(function () {
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
            $table->unsignedBigInteger('user_id');
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
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
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
    }


    protected function tearDown(): void
    {
        \MultiTenantSaas\Context\TenantContext::clear();
        parent::tearDown();
    }
}
