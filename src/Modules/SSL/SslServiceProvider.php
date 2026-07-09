<?php

namespace MultiTenantSaas\Modules\SSL;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class SslServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ssl';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(
            \MultiTenantSaas\Modules\SSL\Services\TenantSslService::class
        );
    }
}
