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
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingGetChangeHistoryHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingGetCurrentPlanHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingGetHistoryHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingGetInvoicesHandler;
use MultiTenantSaas\Modules\Billing\Services\Tools\BillingGetMonthlyReportHandler;
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

        $registry->register('billing_get_current_plan', 'Billing Get Current Plan', 'Get current plan', BillingGetCurrentPlanHandler::class, ['type' => 'object', 'properties' => []], 'billing', 'L1');
        $registry->register('billing_subscribe', 'Billing Subscribe', 'Subscribe', BillingSubscribeHandler::class, ['type' => 'object', 'properties' => ['plan_id' => ['type' => 'integer', 'description' => '计划ID']], 'required' => ['plan_id']], 'billing', 'L2');
        $registry->register('billing_change_plan', 'Billing Change Plan', 'Change plan', BillingChangePlanHandler::class, ['type' => 'object', 'properties' => ['plan_id' => ['type' => 'integer', 'description' => '新计划ID']], 'required' => ['plan_id']], 'billing', 'L2');
        $registry->register('billing_cancel', 'Billing Cancel', 'Cancel', BillingCancelHandler::class, ['type' => 'object', 'properties' => []], 'billing', 'L2');
        $registry->register('billing_start_trial', 'Billing Start Trial', 'Start trial', BillingStartTrialHandler::class, ['type' => 'object', 'properties' => ['plan_id' => ['type' => 'integer', 'description' => '计划ID']], 'required' => ['plan_id']], 'billing', 'L2');
        $registry->register('billing_get_invoices', 'Billing Get Invoices', 'Get invoices', BillingGetInvoicesHandler::class, ['type' => 'object', 'properties' => []], 'billing', 'L1');
        $registry->register('billing_get_history', 'Billing Get History', 'Get history', BillingGetHistoryHandler::class, ['type' => 'object', 'properties' => []], 'billing', 'L1');
        $registry->register('billing_get_change_history', 'Billing Get Change History', 'Get change history', BillingGetChangeHistoryHandler::class, ['type' => 'object', 'properties' => []], 'billing', 'L1');
        $registry->register('billing_get_monthly_report', 'Billing Get Monthly Report', 'Get monthly report', BillingGetMonthlyReportHandler::class, ['type' => 'object', 'properties' => ['month' => ['type' => 'string', 'description' => '月份 YYYY-MM']]], 'billing', 'L1');
        $registry->register('billing_is_in_trial', 'Billing Is In Trial', 'Is in trial', BillingIsInTrialHandler::class, ['type' => 'object', 'properties' => []], 'billing', 'L1');
    }
}
