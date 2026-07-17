<?php

namespace MultiTenantSaas\Modules\Payment;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class PaymentServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'payment';

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

        foreach (['admin.php', 'api.php', 'tenant.php'] as $file) {
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
