<?php

namespace MultiTenantSaas\Modules\Billing;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\SubscriptionService;

class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'billing';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(SubscriptionService::class);
    }
}
