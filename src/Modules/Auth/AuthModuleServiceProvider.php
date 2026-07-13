<?php

namespace MultiTenantSaas\Modules\Auth;

use MultiTenantSaas\Modules\Auth\Services\AlipayOAuthService;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class AuthModuleServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'auth';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(AlipayOAuthService::class);
        $this->app->singleton(SocialiteService::class);
    }
}
