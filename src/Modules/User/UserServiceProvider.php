<?php

namespace MultiTenantSaas\Modules\User;

use Illuminate\Support\Facades\Route;
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

    protected function bootModule(): void
    {
        $this->loadUserRoutes();
    }

    protected function loadUserRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());

        $adminRoute = $moduleDir . '/routes/admin.php';
        if (file_exists($adminRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/v1')
                ->group($adminRoute);
        }

        $tenantRoute = $moduleDir . '/routes/tenant.php';
        if (file_exists($tenantRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/v1')
                ->group($tenantRoute);
        }
    }
}
