<?php

namespace MultiTenantSaas\Modules\Notification;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

use MultiTenantSaas\Modules\Notification\Services\NotificationService;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationGetHistoryHandler;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationGetPreferencesHandler;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationGetUnreadCountHandler;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationListHandler;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationMarkAllReadHandler;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationMarkAsReadHandler;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationSendToTenantUsersHandler;
use MultiTenantSaas\Modules\Notification\Services\Tools\NotificationSendToUserHandler;

class NotificationServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'notification';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(NotificationService::class, fn ($app) => new NotificationService($app->make(TenantContextContract::class)));
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

        $registry->register('notification_list', 'Notification List', 'List', NotificationListHandler::class, ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'description' => '类型过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'notification', 'L1');
        $registry->register('notification_send_to_user', 'Notification Send To User', 'Send to user', NotificationSendToUserHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID'], 'title' => ['type' => 'string', 'description' => '标题'], 'content' => ['type' => 'string', 'description' => '内容'], 'channel' => ['type' => 'string', 'description' => '渠道']], 'required' => ['user_id', 'title', 'content']], 'notification', 'L2');
        $registry->register('notification_send_to_tenant_users', 'Notification Send To Tenant Users', 'Send to tenant users', NotificationSendToTenantUsersHandler::class, ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'description' => '标题'], 'content' => ['type' => 'string', 'description' => '内容'], 'channel' => ['type' => 'string', 'description' => '渠道']], 'required' => ['title', 'content']], 'notification', 'L2');
        $registry->register('notification_get_unread_count', 'Notification Get Unread Count', 'Get unread count', NotificationGetUnreadCountHandler::class, ['type' => 'object', 'properties' => []], 'notification', 'L1');
        $registry->register('notification_mark_as_read', 'Notification Mark As Read', 'Mark as read', NotificationMarkAsReadHandler::class, ['type' => 'object', 'properties' => ['notification_id' => ['type' => 'integer', 'description' => '通知ID']], 'required' => ['notification_id']], 'notification', 'L2');
        $registry->register('notification_mark_all_read', 'Notification Mark All Read', 'Mark all read', NotificationMarkAllReadHandler::class, ['type' => 'object', 'properties' => []], 'notification', 'L2');
        $registry->register('notification_get_preferences', 'Notification Get Preferences', 'Get preferences', NotificationGetPreferencesHandler::class, ['type' => 'object', 'properties' => []], 'notification', 'L1');
        $registry->register('notification_get_history', 'Notification Get History', 'Get history', NotificationGetHistoryHandler::class, ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'notification', 'L1');
    }
}
