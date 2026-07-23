<?php

namespace MultiTenantSaas\Modules\Billing;

use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Billing\Observers\SubscriptionPlanObserver;
use MultiTenantSaas\Modules\Billing\Services\DunningService;
use MultiTenantSaas\Modules\Billing\Services\InvoiceService;
use MultiTenantSaas\Modules\Billing\Services\PayService;
use MultiTenantSaas\Modules\Billing\Services\PlanChangeService;
use MultiTenantSaas\Modules\Billing\Services\RefundService;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Billing\Services\TaxService;
use MultiTenantSaas\Modules\Billing\Services\UsageService;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'billing';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(SubscriptionService::class, fn ($app) => new SubscriptionService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(PayService::class, fn ($app) => new PayService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(InvoiceService::class, fn ($app) => new InvoiceService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(DunningService::class, fn ($app) => new DunningService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(PlanChangeService::class, fn ($app) => new PlanChangeService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(RefundService::class, fn ($app) => new RefundService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(TaxService::class, fn ($app) => new TaxService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(UsageService::class, fn ($app) => new UsageService(
            $app->make(TenantContextContract::class),
        ));
    }

    protected function bootModule(): void
    {
        SubscriptionPlan::observe(SubscriptionPlanObserver::class);
    }
}
