<?php

namespace MultiTenantSaas\Modules\Storage;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Storage\Services\FileService;

use MultiTenantSaas\Modules\Storage\Services\StorageConfigService;
use MultiTenantSaas\Modules\Storage\Services\Tools\StorageCreateShareUrlHandler;
use MultiTenantSaas\Modules\Storage\Services\Tools\StorageDeleteHandler;
use MultiTenantSaas\Modules\Storage\Services\Tools\StorageDownloadHandler;
use MultiTenantSaas\Modules\Storage\Services\Tools\StorageGetUrlHandler;
use MultiTenantSaas\Modules\Storage\Services\Tools\StorageGetUsageHandler;
use MultiTenantSaas\Modules\Storage\Services\Tools\StorageListFilesHandler;
use MultiTenantSaas\Modules\Storage\Services\Tools\StorageUploadHandler;

class StorageServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'storage';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(StorageConfigService::class);
        $this->app->singleton(FileService::class, fn ($app) => new FileService(
            $app->make(TenantContextContract::class),
            $app->make(StorageConfigService::class),
        ));
    }

    protected function bootModule(): void
    {
        $this->registerTools();
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

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('storage_list_files', 'Storage List Files', 'List files', StorageListFilesHandler::class, ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => '目录路径'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'storage', 'L1');
        $registry->register('storage_upload', 'Storage Upload', 'Upload', StorageUploadHandler::class, ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => '目标路径'], 'content' => ['type' => 'string', 'description' => '文件内容(base64)'], 'filename' => ['type' => 'string', 'description' => '文件名']], 'required' => ['path', 'content', 'filename']], 'storage', 'L2');
        $registry->register('storage_delete', 'Storage Delete', 'Delete', StorageDeleteHandler::class, ['type' => 'object', 'properties' => ['file_id' => ['type' => 'integer', 'description' => '文件ID']], 'required' => ['file_id']], 'storage', 'L2');
        $registry->register('storage_download', 'Storage Download', 'Download', StorageDownloadHandler::class, ['type' => 'object', 'properties' => ['file_id' => ['type' => 'integer', 'description' => '文件ID']], 'required' => ['file_id']], 'storage', 'L1');
        $registry->register('storage_get_url', 'Storage Get Url', 'Get url', StorageGetUrlHandler::class, ['type' => 'object', 'properties' => ['file_id' => ['type' => 'integer', 'description' => '文件ID']], 'required' => ['file_id']], 'storage', 'L1');
        $registry->register('storage_create_share_url', 'Storage Create Share Url', 'Create share url', StorageCreateShareUrlHandler::class, ['type' => 'object', 'properties' => ['file_id' => ['type' => 'integer', 'description' => '文件ID'], 'expires_in' => ['type' => 'integer', 'description' => '有效期秒数']], 'required' => ['file_id']], 'storage', 'L2');
        $registry->register('storage_get_usage', 'Storage Get Usage', 'Get usage', StorageGetUsageHandler::class, ['type' => 'object', 'properties' => []], 'storage', 'L1');
    }
}
