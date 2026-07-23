<?php

namespace MultiTenantSaas\Modules\Lottery;

use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;

class LotteryServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'lottery';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(LotteryService::class, fn ($app) => new LotteryService($app->make(TenantContextContract::class)));
    }
}
