<?php

namespace MultiTenantSaas\Modules\ApiToken;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class ApiTokenServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'apitoken';

    protected function registerModuleBindings(): void
    {
        //
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
            $path = $moduleDir . '/routes/' . $file;
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
