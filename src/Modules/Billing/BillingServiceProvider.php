<?php

namespace MultiTenantSaas\Modules\Billing;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'billing';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        //
    }
}
