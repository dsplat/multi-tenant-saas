<?php

namespace MultiTenantSaas\Modules\DeveloperPortal;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\DeveloperPortalService;
use MultiTenantSaas\Services\SandboxService;

class DeveloperPortalServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'developer-portal';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(DeveloperPortalService::class);
        $this->app->singleton(SandboxService::class);
    }
}
