<?php

namespace MultiTenantSaas\Modules\Auth;

use Illuminate\Support\Facades\Route;
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

    protected function bootModule(): void
    {
        $this->loadAuthRoutes();
    }

    protected function loadAuthRoutes(): void
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
