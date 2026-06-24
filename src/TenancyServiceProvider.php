<?php

namespace MultiTenantSaas;

use Illuminate\Support\ServiceProvider;
use MultiTenantSaas\Console\Commands\CheckTenantIsolation;
use MultiTenantSaas\Services\IdGenerator;
use MultiTenantSaas\Services\HealthService;
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
    }

    public function register(): void
    {
        // 合并核心配置
        $this->mergeConfigFrom(__DIR__ . '/../config/tenancy.php', 'tenancy');

        // 合并模块配置
        $this->mergeConfigFrom(__DIR__ . '/Modules/ApiToken/Config/apitoken.php', 'apitoken');
        $this->mergeConfigFrom(__DIR__ . '/Modules/Payment/Config/payment.php', 'payment');

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
    }
}
