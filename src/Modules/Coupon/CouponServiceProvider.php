<?php

namespace MultiTenantSaas\Modules\Coupon;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Coupon\Services\CouponService;

class CouponServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'coupon';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(CouponService::class);
    }
}
