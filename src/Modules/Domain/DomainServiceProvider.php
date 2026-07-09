<?php

namespace MultiTenantSaas\Modules\Domain;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class DomainServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'domain';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(
            \MultiTenantSaas\Modules\Domain\Services\DomainService::class
        );

        $this->app->singleton(
            \MultiTenantSaas\Modules\Domain\Services\NginxConfigService::class
        );
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \MultiTenantSaas\Modules\Domain\Commands\UpdateTenantDomain::class,
                \MultiTenantSaas\Modules\Domain\Commands\GenerateNginxDomainMap::class,
            ]);
        }
    }
}
