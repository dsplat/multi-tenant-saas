<?php

namespace MultiTenantSaas\Modules\Logging;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\StructuredLogService;

class LoggingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'logging';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(StructuredLogService::class);
    }

    protected function bootModule(): void
    {
        $this->loadLoggingRoutes();
    }

    protected function loadLoggingRoutes(): void
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
