<?php

namespace MultiTenantSaas\Modules\Contracts;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * 模块 ServiceProvider 基类
 *
 * 每个模块通过继承此类, 实现标准化的模块注册流程。
 * 路由、迁移、配置均通过 Laravel 标准机制加载。
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    protected string $moduleName;

    protected array $moduleMeta = [];

    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->loadModuleRoutes();
        $this->loadModuleViews();
        $this->loadModuleTranslations();
        $this->registerModuleCommands();
        $this->bootModule();
    }

    public function register(): void
    {
        $this->mergeModuleConfig();
        $this->registerModuleBindings();
    }

    protected function registerModuleBindings(): void {}

    protected function bootModule(): void {}

    protected function registerModuleCommands(): void {}

    /**
     * 合并模块配置
     */
    protected function mergeModuleConfig(): void
    {
        $configPath = $this->getModulePath('Config/' . Str::studly($this->moduleName) . '.php')
            ?? $this->getModulePath('Config/' . strtolower($this->moduleName) . '.php');

        if ($configPath && file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, strtolower($this->moduleName));
        }
    }

    /**
     * 加载模块迁移
     */
    protected function loadModuleMigrations(): void
    {
        $path = $this->getModulePath('Database/migrations');
        if ($path && is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    /**
     * 加载模块路由 (Laravel 标准方式)
     *
     * 约定:
     *   Routes/api.php       → API 路由, 带 auth:sanctum 中间件
     *   Routes/admin.php     → 系统管理路由
     *   Routes/tenant.php    → 租户管理路由
     *   Routes/public.php    → 公开路由 (无认证)
     */
    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = $this->getModulePath();

        // API 路由 (需要认证)
        $apiRoute = $moduleDir . '/Routes/api.php';
        if (file_exists($apiRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/v1')
                ->group($apiRoute);
        }

        // 公开路由 (无需认证，但需要租户识别)
        $publicRoute = $moduleDir . '/Routes/public.php';
        if (file_exists($publicRoute)) {
            Route::middleware(['api'])
                ->prefix('api/v1')
                ->group($publicRoute);
        }

        // 系统管理路由
        $adminRoute = $moduleDir . '/Routes/admin.php';
        if (file_exists($adminRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('v1/admin')
                ->group($adminRoute);
        }

        // 租户管理路由
        $tenantRoute = $moduleDir . '/Routes/tenant.php';
        if (file_exists($tenantRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->group($tenantRoute);
        }
    }

    /**
     * 加载模块视图
     */
    protected function loadModuleViews(): void
    {
        $path = $this->getModulePath('resources/views');
        if ($path && is_dir($path)) {
            $this->loadViewsFrom($path, $this->moduleName);
        }
    }

    /**
     * 加载模块翻译
     */
    protected function loadModuleTranslations(): void
    {
        $path = $this->getModulePath('resources/lang');
        if ($path && is_dir($path)) {
            $this->loadTranslationsFrom($path, $this->moduleName);
        }
    }

    /**
     * 发布模块资源
     */
    public function publishModuleAssets(): void
    {
        $configPath = $this->getModulePath('Config/' . Str::studly($this->moduleName) . '.php')
            ?? $this->getModulePath('Config/' . strtolower($this->moduleName) . '.php');

        if ($configPath && file_exists($configPath)) {
            $this->publishes([
                $configPath => config_path(basename($configPath)),
            ], $this->moduleName . '-config');
        }

        $migrationPath = $this->getModulePath('Database/migrations');
        if ($migrationPath && is_dir($migrationPath)) {
            $this->publishes([
                $migrationPath => database_path('migrations'),
            ], $this->moduleName . '-migrations');
        }

        $viewPath = $this->getModulePath('resources/views');
        if ($viewPath && is_dir($viewPath)) {
            $this->publishes([
                $viewPath => resource_path('views/vendor/' . $this->moduleName),
            ], $this->moduleName . '-views');
        }

        $langPath = $this->getModulePath('resources/lang');
        if ($langPath && is_dir($langPath)) {
            $this->publishes([
                $langPath => resource_path('lang/vendor/' . $this->moduleName),
            ], $this->moduleName . '-lang');
        }
    }

    /**
     * 获取模块目录路径 (或子路径)
     */
    protected function getModulePath(string $relativePath = ''): ?string
    {
        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());
        $fullPath = $moduleDir . ($relativePath ? '/' . $relativePath : '');

        return $fullPath;
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function setModuleMeta(array $meta): void
    {
        $this->moduleMeta = $meta;
    }
}
