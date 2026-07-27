<?php

namespace MultiTenantSaas\Modules\Knowledge;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Knowledge\Services\ExternalKbService;

class KnowledgeServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'knowledge';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(ExternalKbService::class);
    }

    /**
     * 覆写基类路由加载：tenant.php 需挂到 api/v1 前缀（基类默认无前缀），
     * 并带 tenant.identify 以保证 TenantContext 正确解析。
     */
    protected function loadModuleRoutes(): void
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
