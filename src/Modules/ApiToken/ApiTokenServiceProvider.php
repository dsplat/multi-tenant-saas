<?php

namespace MultiTenantSaas\Modules\ApiToken;

use MultiTenantSaas\Modules\ApiToken\Services\ApiTokenService;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class ApiTokenServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'api-token';

    protected function registerModuleBindings(): void
    {
        if (! config('apitoken.enabled', false)) {
            return;
        }

        $this->app->singleton(
            ApiTokenService::class
        );
    }
}
