<?php

namespace MultiTenantSaas\Modules\Workflow;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowEngine;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowRegistry;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Contracts\WorkflowRegistryContract;
use MultiTenantSaas\Contracts\WorkflowServiceContract;

class WorkflowServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'workflow';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(WorkflowEngineContract::class, WorkflowEngine::class);
        $this->app->singleton(WorkflowServiceContract::class, WorkflowService::class);
        $this->app->singleton(WorkflowRegistryContract::class, WorkflowRegistry::class);
    }
}
