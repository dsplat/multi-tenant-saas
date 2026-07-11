<?php

namespace MultiTenantSaas\Modules\Billing;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\SubscriptionService;

class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'billing';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(SubscriptionService::class);
    }

    protected function bootModule(): void
    {
        $this->loadBillingRoutes();
    }

    protected function loadBillingRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());

        // Admin 路由
        $adminRoute = $moduleDir . '/routes/admin.php';
        if (file_exists($adminRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/v1')
                ->group($adminRoute);
        }

        // Tenant 路由
        $tenantRoute = $moduleDir . '/routes/tenant.php';
        if (file_exists($tenantRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/v1')
                ->group($tenantRoute);
        }
    }
}
