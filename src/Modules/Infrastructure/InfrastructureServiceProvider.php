<?php

namespace MultiTenantSaas\Modules\Infrastructure;

use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Observers\TenantObserver;
use MultiTenantSaas\Modules\Infrastructure\Observers\TenantSettingObserver;
use MultiTenantSaas\Modules\Infrastructure\Services\QuotaService;

class InfrastructureServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'infrastructure';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(QuotaService::class, fn ($app) => new QuotaService($app->make(TenantContextContract::class)));
    }

    protected function bootModule(): void
    {
        Tenant::observe(TenantObserver::class);
        TenantSetting::observe(TenantSettingObserver::class);
    }
}
