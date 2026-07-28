<?php

namespace MultiTenantSaas\Modules\Event;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Event\Services\Tools\EventBroadcastAnnouncementHandler;
use MultiTenantSaas\Modules\Event\Services\Tools\EventBroadcastToTenantHandler;
use MultiTenantSaas\Modules\Event\Services\Tools\EventBroadcastToUserHandler;
use MultiTenantSaas\Modules\Event\Services\Tools\EventGetHistoryHandler;

class EventServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'event';

    protected function registerModuleBindings(): void
    {
        //
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

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('event_get_history', 'Event Get History', 'Get history', EventGetHistoryHandler::class, ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'event', 'L1');
        $registry->register('event_broadcast_to_tenant', 'Event Broadcast To Tenant', 'Broadcast to tenant', EventBroadcastToTenantHandler::class, ['type' => 'object', 'properties' => ['event' => ['type' => 'string', 'description' => '事件名'], 'data' => ['type' => 'object', 'description' => '事件数据']], 'required' => ['event', 'data']], 'event', 'L2');
        $registry->register('event_broadcast_to_user', 'Event Broadcast To User', 'Broadcast to user', EventBroadcastToUserHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID'], 'event' => ['type' => 'string', 'description' => '事件名'], 'data' => ['type' => 'object', 'description' => '事件数据']], 'required' => ['user_id', 'event', 'data']], 'event', 'L2');
        $registry->register('event_broadcast_announcement', 'Event Broadcast Announcement', 'Broadcast announcement', EventBroadcastAnnouncementHandler::class, ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'description' => '公告标题'], 'content' => ['type' => 'string', 'description' => '公告内容']], 'required' => ['title', 'content']], 'event', 'L2');
    }
}
