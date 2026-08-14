<?php

namespace MultiTenantSaas\Modules\Form;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Form\Services\FormBuilderService;
use MultiTenantSaas\Modules\Form\Services\Tools\FormCreateHandler;
use MultiTenantSaas\Modules\Form\Services\Tools\FormExportDataHandler;
use MultiTenantSaas\Modules\Form\Services\Tools\FormGetStatisticsHandler;
use MultiTenantSaas\Modules\Form\Services\Tools\FormGetSubmissionsHandler;
use MultiTenantSaas\Modules\Form\Services\Tools\FormListHandler;
use MultiTenantSaas\Modules\Form\Services\Tools\FormSubmitHandler;
use MultiTenantSaas\Modules\Form\Services\Tools\FormUpdateHandler;

class FormServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'form';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(FormBuilderService::class);
    }

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('form_create', 'Form Create', 'Create', FormCreateHandler::class, ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'description' => '表单标题'], 'fields' => ['type' => 'array', 'description' => '字段定义'], 'description' => ['type' => 'string', 'description' => '描述']], 'required' => ['title', 'fields']], 'form', 'L2');
        $registry->register('form_update', 'Form Update', 'Update', FormUpdateHandler::class, ['type' => 'object', 'properties' => ['form_id' => ['type' => 'integer', 'description' => '表单ID'], 'title' => ['type' => 'string', 'description' => '标题'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['form_id']], 'form', 'L2');
        $registry->register('form_get_submissions', 'Form Get Submissions', 'Get submissions', FormGetSubmissionsHandler::class, ['type' => 'object', 'properties' => ['form_id' => ['type' => 'integer', 'description' => '表单ID']], 'required' => ['form_id']], 'form', 'L1');
        $registry->register('form_get_statistics', 'Form Get Statistics', 'Get statistics', FormGetStatisticsHandler::class, ['type' => 'object', 'properties' => ['form_id' => ['type' => 'integer', 'description' => '表单ID']], 'required' => ['form_id']], 'form', 'L1');
        $registry->register('form_export_data', 'Form Export Data', 'Export data', FormExportDataHandler::class, ['type' => 'object', 'properties' => ['form_id' => ['type' => 'integer', 'description' => '表单ID'], 'format' => ['type' => 'string', 'description' => '导出格式']], 'required' => ['form_id']], 'form', 'L1');
        $registry->register('form_list', 'Form List', 'List forms', FormListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']], 'required' => []], 'form', 'L1');
        $registry->register('form_submit', 'Form Submit', 'Submit form', FormSubmitHandler::class, ['type' => 'object', 'properties' => ['form_id' => ['type' => 'integer', 'description' => '表单ID'], 'data' => ['type' => 'object', 'description' => '提交的字段键值对'], 'user_id' => ['type' => 'integer', 'description' => '提交用户ID（可选）']], 'required' => ['form_id', 'data']], 'form', 'L2');
    }
}
