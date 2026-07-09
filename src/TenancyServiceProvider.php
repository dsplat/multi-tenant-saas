<?php

namespace MultiTenantSaas;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use MultiTenantSaas\Console\Commands\CheckTenantIsolation;
use MultiTenantSaas\Console\Commands\MemoryCleanupCommand;
use MultiTenantSaas\Console\Commands\MemoryDecayCommand;
use MultiTenantSaas\Console\Commands\MigrateAgentToolsToWorkflows;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Events\TenantSuspended;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Listeners\LogEventListener;
use MultiTenantSaas\Services\HealthService;
use MultiTenantSaas\Services\IdGenerator;
use MultiTenantSaas\Services\ModuleBootstrapper;
use MultiTenantSaas\Services\ModuleManager;
use MultiTenantSaas\Services\ModuleRegistry;
use MultiTenantSaas\Context\TenantConfigStore;

/**
 * 核心 ServiceProvider
 *
 * 职责:
 * 1. 注册框架根基 (ID生成器、租户上下文、配置存储)
 * 2. 注册模块基础设施 (Registry + Manager + Bootstrapper)
 * 3. 注册限流策略、事件监听、健康检查
 * 4. 调用 ModuleBootstrapper 启动已启用模块
 *
 * 模块不由 Composer auto-discovery 注册 — 由 ModuleBootstrapper 统一控制。
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');
        $this->mergeConfigFrom(__DIR__.'/../config/channel.php', 'channel');

        // 模块基础设施
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(ModuleManager::class);
        $this->app->singleton(ModuleBootstrapper::class);

        // 框架根基
        $this->app->singleton(IdGeneratorContract::class, function () {
            return new IdGenerator;
        });
        $this->app->alias(IdGeneratorContract::class, IdGenerator::class);

        $this->app->singleton(TenantContextContract::class, function () {
            return new TenantContext;
        });
        $this->app->alias(TenantContextContract::class, TenantContext::class);

        $this->app->singleton(TenantConfigStore::class, function () {
            return new TenantConfigStore;
        });
    }

    public function boot(): void
    {
        // 发布资源
        $this->publishes([
            __DIR__.'/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'tenancy-migrations');

        HealthService::registerChecks();

        // Artisan 命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckTenantIsolation::class,
                MemoryDecayCommand::class,
                MemoryCleanupCommand::class,
                MigrateAgentToolsToWorkflows::class,
                \MultiTenantSaas\Console\Commands\ModuleListCommand::class,
                \MultiTenantSaas\Console\Commands\ModuleEnableCommand::class,
                \MultiTenantSaas\Console\Commands\ModuleDisableCommand::class,
                \MultiTenantSaas\Console\Commands\TenancyInitCommand::class,
                \MultiTenantSaas\Console\Commands\ModuleRequireCommand::class,
                \MultiTenantSaas\Console\Commands\ModuleCreateCommand::class,
            ]);
        }

        // 限流策略
        RateLimiter::for('api', function ($request) {
            $user = $request->user();

            return Limit::perMinute(60)->by(
                $user ? $user->getAuthIdentifier() : $request->ip()
            );
        });

        RateLimiter::for('mcp', function ($request) {
            return Limit::perMinute(120)->by(
                $request->header('X-Tenant-ID', $request->ip())
            );
        });

        // 事件监听
        Event::listen(TenantCreated::class, [LogEventListener::class, 'handleTenantCreated']);
        Event::listen(TenantSuspended::class, [LogEventListener::class, 'handleTenantSuspended']);
        Event::listen(TenantActivated::class, [LogEventListener::class, 'handleTenantActivated']);
        Event::listen(UserRegistered::class, [LogEventListener::class, 'handleUserRegistered']);
        Event::listen(UserLoggedIn::class, [LogEventListener::class, 'handleUserLoggedIn']);

        // 启动已启用模块
        $this->app->make(ModuleBootstrapper::class)->bootstrap();
    }
}
