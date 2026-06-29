<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Middleware\CheckFeatureFlag;
use MultiTenantSaas\Middleware\CheckPermission;
use MultiTenantSaas\Middleware\CheckRbacPermission;
use MultiTenantSaas\Middleware\EnsureTenantContext;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();

        // SQLite 无 NOW() 函数，注册自定义函数以兼容源码中 DB::raw('NOW()') 的用法
        DB::connection()->getPdo()->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);

        // 加载项目 lang 目录，使 trans()/__() 在测试中可解析翻译 key
        $langPath = realpath(__DIR__.'/../lang');
        if ($langPath !== false) {
            app('translation.loader')->addPath($langPath);
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('tenant.ensure', EnsureTenantContext::class);
        $router->aliasMiddleware('tenant.permission', CheckPermission::class);
        $router->aliasMiddleware('rbac.permission', CheckRbacPermission::class);
        $router->aliasMiddleware('feature.flag', CheckFeatureFlag::class);

        // 加载 API 路由
        $router->prefix('api')->group(function () {
            require __DIR__.'/../routes/api.php';
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
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
            'model' => User::class,
        ]);

        // 设置 APP_KEY 用于加密
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // 设置缓存为 array 驱动，供 MFA 验证码缓存等使用
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);

        // 设置邮件驱动为 log，避免测试中真实投递
        $app['config']->set('mail.default', 'log');

        // 设置广播驱动为 log，使 isAvailable() 返回 true（部分测试会覆盖为 null 测试降级）
        $app['config']->set('broadcasting.default', 'log');
    }

    protected function setUpDatabase(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigInteger('tenant_id')->unsigned()->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->nullable()->unique();
            $table->string('custom_domain', 200)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->string('isolation_type', 20)->default('shared');
            $table->string('database_name', 100)->nullable();
            $table->string('schema_name', 100)->nullable();
            $table->string('subscription_plan', 50)->default('free');
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->timestamp('subscription_started_at')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('isolation_type', 'tenants_isolation_type_index');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->bigInteger('user_id')->unsigned()->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedInteger('login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
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
            $table->unsignedBigInteger('ai_text_tokens')->default(0);
            $table->unsignedBigInteger('ai_image_generations')->default(0);
            $table->unsignedBigInteger('ai_video_seconds')->default(0);
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

        // AI 网关模块表
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('base_url', 255)->nullable();
            $table->text('api_key')->nullable();
            $table->string('status', 20)->default('active');
            $table->smallInteger('priority')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index('status');
            $table->index('priority');
        });

        Schema::create('ai_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('model', 100);
            $table->string('provider', 50);
            $table->text('prompt_summary')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->decimal('cost', 12, 6)->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'model']);
            $table->index(['tenant_id', 'provider']);
            $table->index('user_id');
            $table->index('status');
        });

        Schema::create('ai_model_aliases', function (Blueprint $table) {
            $table->unsignedBigInteger('alias_id')->primary();
            $table->string('alias', 100);
            $table->string('actual_model', 100);
            $table->string('provider', 50)->nullable();
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deprecated')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique('alias');
            $table->index(['provider', 'type']);
            $table->index('is_active');
        });

        // AI 提示词模板表
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->unsignedBigInteger('prompt_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('category', 50)->default('general');
            $table->text('system_prompt')->nullable();
            $table->text('user_prompt')->nullable();
            $table->json('variables')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'name'], 'idx_tenant_name');
            $table->index('category', 'idx_category');
            $table->index('status', 'idx_status');
        });

        // 租户 AI 配置表
        Schema::create('ai_tenant_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_tenant_config_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('text_enabled')->default(true);
            $table->boolean('image_enabled')->default(true);
            $table->boolean('video_enabled')->default(true);
            $table->json('custom_api_keys')->nullable();
            $table->json('allowed_models')->nullable();
            $table->decimal('monthly_budget_limit', 12, 2)->default(0);
            $table->string('overage_action', 20)->default('block');
            $table->timestamps();

            $table->unique('tenant_id', 'uniq_tenant');
            $table->index('text_enabled');
            $table->index('image_enabled');
            $table->index('video_enabled');
        });

        // 租户 AI 用量配额表
        Schema::create('ai_usage_quotas', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_usage_quota_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->unsignedBigInteger('text_token_limit')->default(0);
            $table->unsignedBigInteger('image_generation_limit')->default(0);
            $table->unsignedBigInteger('video_duration_limit')->default(0);
            $table->string('period', 20)->default('monthly');
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->unsignedBigInteger('used_images')->default(0);
            $table->unsignedBigInteger('used_video_seconds')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'period'], 'uniq_tenant_period');
            $table->index(['tenant_id', 'period']);
            $table->index('subscription_plan_id');
        });

        // MFA 设备表
        Schema::create('mfa_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('mfa_device_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('type', 20);
            $table->text('secret')->nullable();
            $table->string('label', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'type']);
            $table->unique(['user_id', 'type']);
        });

        // MFA 恢复码表
        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('recovery_code_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('code', 255);
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'is_used']);
        });

        // 用户会话表
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_session_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_info', 500)->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->boolean('is_anomalous')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'last_active_at']);
            $table->index('token_id');
            $table->index('device_fingerprint');
        });

        // 密码历史表
        Schema::create('password_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('password_history_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('password_hash');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        // SSO 提供方表
        Schema::create('sso_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('sso_provider_id')->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('type', 20);
            $table->string('name', 100);
            $table->string('display_name', 200)->nullable();
            $table->string('entity_id', 500)->nullable();
            $table->string('metadata_url', 500)->nullable();
            $table->text('certificate')->nullable();
            $table->string('sso_url', 500)->nullable();
            $table->string('slo_url', 500)->nullable();
            $table->string('client_id', 200)->nullable();
            $table->text('client_secret')->nullable();
            $table->string('authorize_url', 500)->nullable();
            $table->string('token_url', 500)->nullable();
            $table->string('userinfo_url', 500)->nullable();
            $table->string('scope', 200)->default('openid profile email');
            $table->json('attribute_mapping')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'name']);
        });

        // IP 白名单表
        Schema::create('ip_whitelists', function (Blueprint $table) {
            $table->unsignedBigInteger('ip_whitelist_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('ip_value', 100);
            $table->string('description', 255)->nullable();
            $table->string('scope', 20)->default('all');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_enabled']);
            $table->index(['tenant_id', 'scope']);
        });

        // 信任设备表
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('trusted_device_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('device_fingerprint', 64);
            $table->string('device_name', 200)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'device_fingerprint']);
            $table->index(['user_id', 'expires_at']);
            $table->unique(['user_id', 'device_fingerprint'], 'uniq_user_fingerprint');
        });

        // 用户同意记录表（GDPR 合规）
        Schema::create('consents', function (Blueprint $table) {
            $table->unsignedBigInteger('consent_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 50);
            $table->string('version', 50)->default('1.0');
            $table->boolean('is_granted')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['tenant_id', 'type']);
            $table->index(['is_granted', 'revoked_at']);
        });

        // 数据保留策略表（GDPR 合规）
        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->unsignedBigInteger('data_retention_policy_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('data_type', 50);
            $table->unsignedInteger('retention_days')->default(365);
            $table->boolean('auto_cleanup')->default(false);
            $table->string('cleanup_strategy', 20)->default('delete');
            $table->boolean('is_exempt')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'data_type'], 'uniq_retention_tenant_type');
            $table->index(['auto_cleanup', 'is_exempt']);
            $table->index('data_type');
        });

        // Webhook 端点表
        Schema::create('webhooks', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('url', 500);
            $table->json('events');
            $table->string('secret', 128);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_active']);
        });

        // Webhook 交付记录表
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_delivery_id')->primary();
            $table->unsignedBigInteger('webhook_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 100);
            $table->json('payload');
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('webhook_id');
            $table->index('tenant_id');
            $table->index(['webhook_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index('event_type');
        });

        // 事件订阅表
        Schema::create('event_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('event_subscription_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 100);
            $table->string('subscription_type', 20)->default('internal');
            $table->string('handler', 500);
            $table->string('secret', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_active']);
            $table->index('event_type');
            $table->unique(['tenant_id', 'event_type', 'handler'], 'uniq_tenant_event_handler');
        });

        // 死信队列表
        Schema::create('dead_letters', function (Blueprint $table) {
            $table->unsignedBigInteger('dead_letter_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('event_type', 100);
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->json('original_data')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('status', 20)->default('failed');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('event_type');
            $table->index(['tenant_id', 'status']);
        });

        // 沙箱环境表（开发者门户 - TASK-021）
        Schema::create('sandbox_environments', function (Blueprint $table) {
            $table->unsignedBigInteger('sandbox_environment_id')->primary();
            $table->unsignedBigInteger('developer_id')->index();
            $table->unsignedBigInteger('sandbox_tenant_id')->index();
            $table->string('api_key', 128)->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['developer_id', 'status']);
            $table->index('expires_at');
        });

        // 功能开关表（TASK-022）
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->unsignedBigInteger('feature_flag_id')->primary();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->string('scope', 20)->default('global');
            $table->json('conditions')->nullable();
            $table->json('dependencies')->nullable();
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->string('status', 20)->default('inactive');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('scope');
        });

        // 指标快照表（TASK-023）
        Schema::create('metrics_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('metrics_snapshot_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('metric_name', 100);
            $table->double('metric_value')->default(0);
            $table->string('dimension_type', 30)->nullable();
            $table->string('dimension_value', 200)->nullable();
            $table->string('granularity', 10)->default('minute');
            $table->boolean('aggregated')->default(false);
            $table->timestamp('sampled_at');
            $table->timestamps();

            $table->index(['metric_name', 'granularity', 'sampled_at']);
            $table->index(['tenant_id', 'metric_name', 'sampled_at']);
            $table->index(['dimension_type', 'dimension_value']);
            $table->index('sampled_at');
        });

        // SLA 事件表（TASK-023）
        Schema::create('sla_events', function (Blueprint $table) {
            $table->unsignedBigInteger('sla_event_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('event_type', 20);
            $table->string('severity', 20)->default('warning');
            $table->string('affected_scope', 100)->default('global');
            $table->unsignedInteger('affected_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_sec')->default(0);
            $table->string('status', 20)->default('active');
            $table->text('root_cause')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'started_at']);
            $table->index(['event_type', 'started_at']);
            $table->index('status');
            $table->index('started_at');
        });

        // 成本分摊表（TASK-024）
        Schema::create('cost_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_allocation_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('cost_type', 30);
            $table->string('cost_subtype', 50)->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('currency', 10)->default('CNY');
            $table->string('period', 7);
            $table->string('allocation_basis', 100)->nullable();
            $table->decimal('allocation_value', 14, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period', 'cost_type']);
            $table->index(['period', 'cost_type']);
            $table->index('tenant_id');
        });

        // 自定义报表表（TASK-025）
        Schema::create('custom_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('custom_report_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->json('metrics_config')->nullable();
            $table->json('dimensions')->nullable();
            $table->string('time_range', 30)->default('last_7_days');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('frequency', 20)->default('daily');
            $table->json('recipients')->nullable();
            $table->string('format', 20)->default('csv');
            $table->string('template', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'frequency']);
            $table->index('next_send_at');
        });

        // 站内通知表（TASK-026）
        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('in_app_notification_id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 30)->default('system');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'is_read'], 'idx_tenant_user_read');
            $table->index(['tenant_id', 'user_id', 'type'], 'idx_tenant_user_type');
            $table->index(['user_id', 'is_read'], 'idx_user_read');
        });

        // 广播事件表（TASK-026）
        Schema::create('broadcast_events', function (Blueprint $table) {
            $table->unsignedBigInteger('broadcast_event_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('event_type', 100);
            $table->string('channel', 200);
            $table->json('payload');
            $table->boolean('is_sent')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'event_type', 'is_sent'], 'idx_tenant_event_sent');
            $table->index('channel');
            $table->index('is_sent');
        });

        // 租户加密密钥表（TASK-028）
        Schema::create('tenant_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_key_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->text('encrypted_key');
            $table->string('key_type', 20)->default('system');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('previous_key_id')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id', 'tk_tenant_index');
            $table->index(['tenant_id', 'status'], 'tk_tenant_status_index');
            $table->index('previous_key_id', 'tk_previous_index');
        });

        // 白标品牌配置表（TASK-028）
        Schema::create('branding_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('branding_config_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('logo_url', 500)->nullable();
            $table->string('favicon_url', 500)->nullable();
            $table->string('primary_color', 20)->nullable();
            $table->string('secondary_color', 20)->nullable();
            $table->text('custom_css')->nullable();
            $table->string('custom_domain', 200)->nullable();
            $table->string('login_page_style', 20)->default('default');
            $table->string('email_template', 50)->default('default');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('tenant_id', 'bc_tenant_unique');
            $table->unique('custom_domain', 'bc_domain_unique');
        });
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }
}
