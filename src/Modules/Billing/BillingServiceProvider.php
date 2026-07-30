<?php

namespace MultiTenantSaas\Modules\Billing;

use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Billing\Observers\SubscriptionPlanObserver;
use MultiTenantSaas\Modules\Billing\Services\DunningService;
use MultiTenantSaas\Modules\Billing\Services\InvoiceService;
use MultiTenantSaas\Modules\Billing\Services\PayService;
use MultiTenantSaas\Modules\Billing\Services\PlanChangeService;
use MultiTenantSaas\Modules\Billing\Services\RefundService;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Billing\Services\TaxService;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingCancelHandler;

use MultiTenantSaas\Modules\Billing\Services\Tools\BillingChangePlanHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingGetCurrentPlanHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingGetHistoryHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingIsInTrialHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingStartTrialHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingSubscribeHandler;
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
        $this->registerTools();
        SubscriptionPlan::observe(SubscriptionPlanObserver::class);
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $emptySchema = ['type' => 'object', 'properties' => []];
        $planIdSchema = fn (string $desc) => ['type' => 'object', 'properties' => ['plan_id' => ['type' => 'integer', 'description' => $desc]], 'required' => ['plan_id']];

        $tools = [
            ['billing_get_current_plan', 'Billing Get Current Plan', 'Get current plan', BillingGetCurrentPlanHandler::class, $emptySchema, 'L1'],
            ['billing_subscribe', 'Billing Subscribe', 'Subscribe', BillingSubscribeHandler::class, $planIdSchema('计划ID'), 'L2'],
            ['billing_change_plan', 'Billing Change Plan', 'Change plan', BillingChangePlanHandler::class, $planIdSchema('新计划ID'), 'L2'],
            ['billing_cancel', 'Billing Cancel', 'Cancel', BillingCancelHandler::class, $emptySchema, 'L2'],
            ['billing_start_trial', 'Billing Start Trial', 'Start trial', BillingStartTrialHandler::class, $planIdSchema('计划ID'), 'L2'],
            ['billing_get_history', 'Billing Get History', 'Get history', BillingGetHistoryHandler::class, $emptySchema, 'L1'],
            ['billing_is_in_trial', 'Billing Is In Trial', 'Is in trial', BillingIsInTrialHandler::class, $emptySchema, 'L1'],
        ];

        foreach ($tools as [$name, $title, $description, $handler, $schema, $level]) {
            $registry->register($name, $title, $description, $handler, $schema, 'billing', $level);
        }
    }
}
