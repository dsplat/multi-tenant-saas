<?php

namespace MultiTenantSaas\Modules\User;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\LoginLogService;
use MultiTenantSaas\Services\UserPreferenceService;
use MultiTenantSaas\Services\UserProfileService;

class UserServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'user';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(UserProfileService::class);
        $this->app->singleton(UserPreferenceService::class);
        $this->app->singleton(LoginLogService::class);
    }
}
