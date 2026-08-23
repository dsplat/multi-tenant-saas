<?php

namespace MultiTenantSaas\Modules\Domain;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Domain\Commands\BackfillAutoSlug;
use MultiTenantSaas\Modules\Domain\Commands\GenerateNginxDeploy;
use MultiTenantSaas\Modules\Domain\Commands\GenerateNginxDomainMap;

class DomainServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'domain';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateNginxDomainMap::class,
                GenerateNginxDeploy::class,
                BackfillAutoSlug::class,
            ]);
        }
    }

    protected function bootModule(): void
    {
        $this->loadAdminTenantRoutes();
        $this->registerVerificationFileRoutes();
        $this->loadModuleViews();
    }

    /**
     * 域名验证文件动态服务路由（裸路由，无中间件链）
     *
     * 不挂 web 组：规避 EnforceCanonicalEntry 对 pending 自定义域名的 301 收敛
     * （验证文件必须在域名未生效时即可访问）。控制器内按 Host 手动解析租户。
     */
    protected function registerVerificationFileRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());
        $path = $moduleDir . '/Routes/verify-file.php';

        if (file_exists($path)) {
            $this->loadRoutesFrom($path);
        }
    }

    protected function loadAdminTenantRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());

        // tenant.php 由基类统一挂 api/v1 前缀 + tenant.identify
        foreach (['admin.php'] as $file) {
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
