<?php

namespace MultiTenantSaas\Modules\Monitoring;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\AlertService;
use MultiTenantSaas\Services\MetricsService;
use MultiTenantSaas\Services\PerformanceService;
use MultiTenantSaas\Services\SlaService;

class MonitoringServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'monitoring';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(MetricsService::class);
        $this->app->singleton(SlaService::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(PerformanceService::class);
    }
}
