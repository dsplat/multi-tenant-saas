<?php

namespace MultiTenantSaas\Modules\SSL;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\SSL\Commands\AutoIssueSsl;

class SSLServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ssl';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AutoIssueSsl::class,
            ]);
        }
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

        // tenant.php 由基类统一挂 api/v1 前缀 + tenant.identify
        foreach (['admin.php', 'api.php'] as $file) {
            $path = $moduleDir . '/Routes/' . $file;
            if (file_exists($path)) {
                $middleware = ['auth:sanctum', 'throttle:api'];
                if ($file !== 'admin.php') {
                    $middleware[] = 'tenant.identify';
                }
                Route::middleware($middleware)
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
