<?php

namespace MultiTenantSaas;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use MultiTenantSaas\Console\Commands\CheckTenantIsolation;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Events\TenantSuspended;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Listeners\LogEventListener;
use MultiTenantSaas\Services\AlipayOAuthService;
use MultiTenantSaas\Services\AlertService;
use MultiTenantSaas\Services\ApiVersionService;
use MultiTenantSaas\Services\CacheService;
use MultiTenantSaas\Services\ExportService;
use MultiTenantSaas\Services\IdGenerator;
use MultiTenantSaas\Services\HealthService;
use MultiTenantSaas\Services\LoginLogService;
use MultiTenantSaas\Services\PaymentSecurityService;
use MultiTenantSaas\Services\PerformanceService;
use MultiTenantSaas\Services\PluginService;
use MultiTenantSaas\Services\QueueService;
use MultiTenantSaas\Services\RateLimitService;
use MultiTenantSaas\Services\SocialiteService;
use MultiTenantSaas\Services\StructuredLogService;
use MultiTenantSaas\Services\SubscriptionService;
use MultiTenantSaas\Services\TenantProfileService;
use MultiTenantSaas\Services\UserPreferenceService;
use MultiTenantSaas\Services\UserProfileService;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Context\TenantConfigStore;

class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 发布核心配置
        $this->publishes([
            __DIR__ . '/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        // 发布迁移
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'tenancy-migrations');

        // 发布模块配置
        $this->publishes([
            __DIR__ . '/Modules/ApiToken/Config/apitoken.php' => config_path('apitoken.php'),
            __DIR__ . '/Modules/Payment/Config/payment.php' => config_path('payment.php'),
        ], 'tenancy-modules-config');

        // 注册健康检查
        HealthService::registerChecks();

        // 注册 Artisan 命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckTenantIsolation::class,
            ]);
        }

        // 注册认证后 API 限流策略
        // 按用户 ID 限流，每分钟 60 次
        RateLimiter::for('api', function ($request) {
            $user = $request->user();
            return Limit::perMinute(60)->by(
                $user ? $user->getAuthIdentifier() : $request->ip()
            );
        });

        // 注册事件监听器（事件系统）
        Event::listen(TenantCreated::class, [LogEventListener::class, 'handleTenantCreated']);
        Event::listen(TenantSuspended::class, [LogEventListener::class, 'handleTenantSuspended']);
        Event::listen(TenantActivated::class, [LogEventListener::class, 'handleTenantActivated']);
        Event::listen(UserRegistered::class, [LogEventListener::class, 'handleUserRegistered']);
        Event::listen(UserLoggedIn::class, [LogEventListener::class, 'handleUserLoggedIn']);
    }

    public function register(): void
    {
        // 合并核心配置
        $this->mergeConfigFrom(__DIR__ . '/../config/tenancy.php', 'tenancy');

        // 合并模块配置
        $this->mergeConfigFrom(__DIR__ . '/Modules/ApiToken/Config/apitoken.php', 'apitoken');
        $this->mergeConfigFrom(__DIR__ . '/Modules/Payment/Config/payment.php', 'payment');

        // 注册ID生成器（绑定接口契约 + 具体实现）
        $this->app->singleton(IdGeneratorContract::class, function () {
            return new IdGenerator();
        });
        $this->app->alias(IdGeneratorContract::class, IdGenerator::class);

        // 注册租户上下文（绑定接口契约 + 具体实现）
        $this->app->singleton(TenantContextContract::class, function () {
            return new TenantContext();
        });
        $this->app->alias(TenantContextContract::class, TenantContext::class);

        // 注册配置存储
        $this->app->singleton(TenantConfigStore::class, function () {
            return new TenantConfigStore();
        });

        // 注册 ApiToken 模块服务（仅在启用时）
        if (config('apitoken.enabled', false)) {
            $this->app->singleton(
                \MultiTenantSaas\Modules\ApiToken\Services\ApiTokenService::class
            );
        }

        // 注册 Payment 模块服务（仅在启用时）
        if (config('payment.enabled', false)) {
            $this->app->singleton(
                \MultiTenantSaas\Modules\Payment\Services\PaymentService::class
            );
        }

        // 注册支付宝 OAuth 服务
        $this->app->singleton(AlipayOAuthService::class);

        // 注册核心业务服务
        $this->app->singleton(UserProfileService::class);
        $this->app->singleton(UserPreferenceService::class);
        $this->app->singleton(LoginLogService::class);
        $this->app->singleton(StructuredLogService::class);
        $this->app->singleton(ApiVersionService::class);
        $this->app->singleton(ExportService::class);
        $this->app->singleton(PluginService::class);
        $this->app->singleton(RateLimitService::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(PerformanceService::class);
        $this->app->singleton(CacheService::class);
        $this->app->singleton(PaymentSecurityService::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(TenantProfileService::class);
        $this->app->singleton(QueueService::class);
        $this->app->singleton(SocialiteService::class);
    }
}
