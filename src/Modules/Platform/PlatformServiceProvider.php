<?php

namespace MultiTenantSaas\Modules\Platform;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'platform';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->loadPlatformRoutes();
    }

    protected function loadPlatformRoutes(): void
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
