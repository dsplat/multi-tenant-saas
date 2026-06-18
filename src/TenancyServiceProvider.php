<?php

namespace MultiTenantSaas;

use Illuminate\Support\ServiceProvider;
use MultiTenantSaas\Console\Commands\CheckTenantIsolation;
use MultiTenantSaas\Services\IdGenerator;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Context\TenantConfigStore;

class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 发布配置
        $this->publishes([
            __DIR__ . '/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        // 发布迁移
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'tenancy-migrations');

        // 注册 Artisan 命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckTenantIsolation::class,
            ]);
        }
    }

    public function register(): void
    {
        // 合并配置
        $this->mergeConfigFrom(__DIR__ . '/../config/tenancy.php', 'tenancy');

        // 注册ID生成器
        $this->app->singleton(IdGenerator::class, function () {
            return new IdGenerator();
        });

        // 注册租户上下文
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });

        // 注册配置存储
        $this->app->singleton(TenantConfigStore::class, function () {
            return new TenantConfigStore();
        });
    }
}
