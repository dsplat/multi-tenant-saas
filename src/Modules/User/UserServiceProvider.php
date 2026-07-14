<?php

namespace MultiTenantSaas\Modules\User;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class UserServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'user';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        //
    }
}
