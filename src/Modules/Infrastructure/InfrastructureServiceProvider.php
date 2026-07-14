<?php

namespace MultiTenantSaas\Modules\Infrastructure;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class InfrastructureServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'infrastructure';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        //
    }
}
