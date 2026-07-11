<?php

namespace MultiTenantSaas\Modules\Infrastructure;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class InfrastructureServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'infrastructure';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->loadInfrastructureRoutes();
    }

    protected function loadInfrastructureRoutes(): void
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
    }
}
