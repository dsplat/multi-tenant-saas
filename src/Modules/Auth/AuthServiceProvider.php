<?php

namespace MultiTenantSaas\Modules\Auth;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class AuthServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'auth';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        //
    }
}
