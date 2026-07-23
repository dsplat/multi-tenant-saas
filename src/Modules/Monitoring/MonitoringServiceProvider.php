<?php

namespace MultiTenantSaas\Modules\Monitoring;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Monitoring\Services\TrialService;

class MonitoringServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'monitoring';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(TrialService::class, fn ($app) => new TrialService($app->make(TenantContextContract::class)));
    }

    protected function bootModule(): void
    {
        $this->loadAdminTenantRoutes();
        $this->loadModuleViews();
    }

    protected function loadAdminTenantRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());

        foreach (['admin.php', 'tenant.php'] as $file) {
            $path = $moduleDir . '/Routes/' . $file;
            if (file_exists($path)) {
                Route::middleware(['auth:sanctum', 'throttle:api'])
                    ->prefix('api/v1')
                    ->group($path);
            }
        }
    }

    protected function loadModuleViews(): void
    {
        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());
        $viewsDir = $moduleDir . '/resources/views';

        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'module.' . $this->moduleName);
        }
    }
}
