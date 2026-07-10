<?php

namespace MultiTenantSaas\Modules\Domain;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Domain\Commands\GenerateNginxDomainMap;
use MultiTenantSaas\Modules\Domain\Commands\UpdateTenantDomain;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Domain\Services\NginxConfigService;

class DomainServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'domain';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(
            DomainService::class
        );

        $this->app->singleton(
            NginxConfigService::class
        );
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateTenantDomain::class,
                GenerateNginxDomainMap::class,
            ]);
        }
    }
}
