<?php

namespace MultiTenantSaas\Modules\SSL;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\SSL\Services\TenantSslService;

class SslServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ssl';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(
            TenantSslService::class
        );
    }
}
