<?php

namespace MultiTenantSaas\Modules\Conversation;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Conversation\Services\Tools\ConversationCreateHandler;
use MultiTenantSaas\Modules\Conversation\Services\Tools\ConversationDeleteHandler;
use MultiTenantSaas\Modules\Conversation\Services\Tools\ConversationGetHandler;
use MultiTenantSaas\Modules\Conversation\Services\Tools\ConversationListHandler;

class ConversationServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'conversation';

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

        $registry->register('conversation_list', 'Conversation List', 'List', ConversationListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'conversation', 'L1');
        $registry->register('conversation_get', 'Conversation Get', 'Get', ConversationGetHandler::class, ['type' => 'object', 'properties' => ['conversation_id' => ['type' => 'integer', 'description' => '会话ID']], 'required' => ['conversation_id']], 'conversation', 'L1');
        $registry->register('conversation_create', 'Conversation Create', 'Create', ConversationCreateHandler::class, ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'description' => '标题'], 'type' => ['type' => 'string', 'description' => '类型'], 'participant_ids' => ['type' => 'array', 'description' => '参与者ID列表']], 'required' => ['title']], 'conversation', 'L2');
        $registry->register('conversation_delete', 'Conversation Delete', 'Delete', ConversationDeleteHandler::class, ['type' => 'object', 'properties' => ['conversation_id' => ['type' => 'integer', 'description' => '会话ID']], 'required' => ['conversation_id']], 'conversation', 'L2');
    }
}
