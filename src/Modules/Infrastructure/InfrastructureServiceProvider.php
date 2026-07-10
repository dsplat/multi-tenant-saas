<?php

namespace MultiTenantSaas\Modules\Infrastructure;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\CacheService;
use MultiTenantSaas\Services\FeatureFlagService;
use MultiTenantSaas\Services\QueueService;
use MultiTenantSaas\Services\RateLimitService;
use MultiTenantSaas\Services\ResourceService;

class InfrastructureServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'infrastructure';

    protected function registerModuleBindings(): void
    {
        // 基础设施服务 (按依赖顺序: 先被依赖的先注册)
        $this->app->singleton(CacheService::class);
        $this->app->singleton(QueueService::class);
        $this->app->singleton(RateLimitService::class);
        $this->app->singleton(FeatureFlagService::class);
        $this->app->singleton(ResourceService::class);
    }
}
