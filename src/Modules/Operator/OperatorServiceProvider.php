<?php

namespace MultiTenantSaas\Modules\Operator;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Operator\Services\OperatorService;

class OperatorServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'operator';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(OperatorService::class);
    }

    protected function bootModule(): void
    {
        //
    }
}
