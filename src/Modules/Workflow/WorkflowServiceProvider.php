<?php

namespace MultiTenantSaas\Modules\Workflow;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Workflow\Services\Tools\WorkflowCreateHandler;

use MultiTenantSaas\Modules\Workflow\Services\Tools\WorkflowDeleteHandler;
use MultiTenantSaas\Modules\Workflow\Services\Tools\WorkflowGetHandler;
use MultiTenantSaas\Modules\Workflow\Services\Tools\WorkflowListHandler;
use MultiTenantSaas\Modules\Workflow\Services\Tools\WorkflowStartHandler;
use MultiTenantSaas\Modules\Workflow\Services\Tools\WorkflowUpdateHandler;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowEngine;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;

class WorkflowServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'workflow';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(WorkflowEngineContract::class, WorkflowEngine::class);
        $this->app->singleton(WorkflowService::class);
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

        $registry->register('workflow_list', 'Workflow List', 'List', WorkflowListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤']]], 'workflow', 'L1');
        $registry->register('workflow_get', 'Workflow Get', 'Get', WorkflowGetHandler::class, ['type' => 'object', 'properties' => ['workflow_id' => ['type' => 'integer', 'description' => '工作流ID']], 'required' => ['workflow_id']], 'workflow', 'L1');
        $registry->register('workflow_create', 'Workflow Create', 'Create', WorkflowCreateHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '名称'], 'definition' => ['type' => 'object', 'description' => '流程定义'], 'trigger' => ['type' => 'string', 'description' => '触发条件']], 'required' => ['name', 'definition']], 'workflow', 'L2');
        $registry->register('workflow_update', 'Workflow Update', 'Update', WorkflowUpdateHandler::class, ['type' => 'object', 'properties' => ['workflow_id' => ['type' => 'integer', 'description' => '工作流ID'], 'name' => ['type' => 'string', 'description' => '名称'], 'definition' => ['type' => 'object', 'description' => '定义']], 'required' => ['workflow_id']], 'workflow', 'L2');
        $registry->register('workflow_delete', 'Workflow Delete', 'Delete', WorkflowDeleteHandler::class, ['type' => 'object', 'properties' => ['workflow_id' => ['type' => 'integer', 'description' => '工作流ID']], 'required' => ['workflow_id']], 'workflow', 'L2');
        $registry->register('workflow_start', 'Workflow Start', 'Start', WorkflowStartHandler::class, ['type' => 'object', 'properties' => ['workflow_id' => ['type' => 'integer', 'description' => '工作流ID'], 'input' => ['type' => 'object', 'description' => '输入数据']], 'required' => ['workflow_id']], 'workflow', 'L2');
    }
}
