<?php

namespace MultiTenantSaas\Modules\Auth;

use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Auth\Services\AlipayOAuthService;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class AuthModuleServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'auth';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(AlipayOAuthService::class);
        $this->app->singleton(RbacService::class, fn ($app) => new RbacService(
            $app->make(TenantContextContract::class),
        ));
        $this->app->singleton(SocialiteService::class, fn ($app) => new SocialiteService(
            $app->make(TenantContextContract::class),
        ));
    }
}
