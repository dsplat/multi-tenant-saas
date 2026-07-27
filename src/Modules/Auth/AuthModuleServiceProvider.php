<?php

namespace MultiTenantSaas\Modules\Auth;

use Illuminate\Support\Facades\Route;
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

    protected function bootModule(): void
    {
        $this->loadTenantApiRoutes();
    }

    /**
     * 以 api/v1 前缀注册租户后台路由（tenant.php）
     *
     * 基类 loadModuleRoutes() 对 tenant.php 不加前缀，而生产 nginx 仅转发
     * /api/* 到 PHP，console 前端实际调用 /api/v1/tenant/auth/...，
     * 故仿照 SSL/ApiToken 模块范式补带前缀注册。
     */
    protected function loadTenantApiRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $tenantRoute = $this->getModulePath('Routes/tenant.php');
        if ($tenantRoute && file_exists($tenantRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.identify'])
                ->prefix('api/v1')
                ->group($tenantRoute);
        }
    }
}
