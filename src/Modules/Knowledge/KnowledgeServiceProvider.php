<?php

namespace MultiTenantSaas\Modules\Knowledge;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Knowledge\Services\ExternalKbService;
use MultiTenantSaas\Modules\Knowledge\Services\Tools\KnowledgeCreateConnectionHandler;
use MultiTenantSaas\Modules\Knowledge\Services\Tools\KnowledgeDeleteConnectionHandler;
use MultiTenantSaas\Modules\Knowledge\Services\Tools\KnowledgeListConnectionsHandler;
use MultiTenantSaas\Modules\Knowledge\Services\Tools\KnowledgePushDocumentHandler;
use MultiTenantSaas\Modules\Knowledge\Services\Tools\KnowledgeTestConnectionHandler;
use MultiTenantSaas\Modules\Knowledge\Services\Tools\KnowledgeUpdateConnectionHandler;

class KnowledgeServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'knowledge';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(ExternalKbService::class);
    }

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('knowledge_list_connections', 'Knowledge List Connections', 'List connections', KnowledgeListConnectionsHandler::class, ['type' => 'object', 'properties' => []], 'knowledge', 'L1');
        $registry->register('knowledge_create_connection', 'Knowledge Create Connection', 'Create connection', KnowledgeCreateConnectionHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '连接名称'], 'provider_type' => ['type' => 'string', 'description' => '提供者类型'], 'config' => ['type' => 'object', 'description' => '配置']], 'required' => ['name', 'provider_type', 'config']], 'knowledge', 'L2');
        $registry->register('knowledge_update_connection', 'Knowledge Update Connection', 'Update connection', KnowledgeUpdateConnectionHandler::class, ['type' => 'object', 'properties' => ['connection_id' => ['type' => 'integer', 'description' => '连接ID'], 'name' => ['type' => 'string', 'description' => '名称'], 'config' => ['type' => 'object', 'description' => '配置']], 'required' => ['connection_id']], 'knowledge', 'L2');
        $registry->register('knowledge_delete_connection', 'Knowledge Delete Connection', 'Delete connection', KnowledgeDeleteConnectionHandler::class, ['type' => 'object', 'properties' => ['connection_id' => ['type' => 'integer', 'description' => '连接ID']], 'required' => ['connection_id']], 'knowledge', 'L2');
        $registry->register('knowledge_test_connection', 'Knowledge Test Connection', 'Test connection', KnowledgeTestConnectionHandler::class, ['type' => 'object', 'properties' => ['connection_id' => ['type' => 'integer', 'description' => '连接ID']], 'required' => ['connection_id']], 'knowledge', 'L1');
        $registry->register('knowledge_push_document', 'Knowledge Push Document', 'Push document', KnowledgePushDocumentHandler::class, ['type' => 'object', 'properties' => ['connection_id' => ['type' => 'integer', 'description' => '连接ID'], 'name' => ['type' => 'string', 'description' => '文档名称'], 'content' => ['type' => 'string', 'description' => '文档内容']], 'required' => ['connection_id', 'name', 'content']], 'knowledge', 'L2');
    }
}
