<?php

namespace MultiTenantSaas\Modules\Platform;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\ExportService;
use MultiTenantSaas\Services\ApiVersionService;
use MultiTenantSaas\Services\TenantProfileService;
use MultiTenantSaas\Services\CostService;
use MultiTenantSaas\Services\EventBusService;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'platform';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(ExportService::class);
        $this->app->singleton(ApiVersionService::class);
        $this->app->singleton(TenantProfileService::class);
        $this->app->singleton(CostService::class);
        $this->app->singleton(EventBusService::class);
    }
}
