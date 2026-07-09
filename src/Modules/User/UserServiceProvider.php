<?php

namespace MultiTenantSaas\Modules\User;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\UserProfileService;
use MultiTenantSaas\Services\UserPreferenceService;
use MultiTenantSaas\Services\LoginLogService;

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
