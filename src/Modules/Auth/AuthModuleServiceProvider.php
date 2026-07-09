<?php

namespace MultiTenantSaas\Modules\Auth;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\AlipayOAuthService;
use MultiTenantSaas\Services\SocialiteService;

class AuthModuleServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'auth';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(AlipayOAuthService::class);
        $this->app->singleton(SocialiteService::class);
    }
}
