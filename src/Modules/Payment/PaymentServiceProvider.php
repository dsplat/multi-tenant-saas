<?php

namespace MultiTenantSaas\Modules\Payment;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Payment\Services\PaymentService;
use MultiTenantSaas\Services\PaymentSecurityService;

class PaymentServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'payment';

    protected function registerModuleBindings(): void
    {
        if (! config('payment.enabled', false)) {
            return;
        }

        $this->app->singleton(
            PaymentService::class
        );

        // 支付安全服务（风控、反欺诈）
        $this->app->singleton(PaymentSecurityService::class);
    }
}
