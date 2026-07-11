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

        // 公开路由（无需认证）
        $publicRoute = $moduleDir . '/Routes/public.php';
        if (file_exists($publicRoute)) {
            Route::prefix('api/v1')->group($publicRoute);
        }

        // 认证路由
        $apiRoute = $moduleDir . '/Routes/api.php';
        if (file_exists($apiRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/v1')
                ->group($apiRoute);
        }
    }
}
