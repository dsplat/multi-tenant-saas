<?php

namespace MultiTenantSaas\Modules\Sms;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Sms\Services\SmsService;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsCancelBatchTaskHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsCreateTemplateHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsGetBatchTaskHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsGetDeliveryStatsHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsGetUnsubscribesHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsRenderContentHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsScheduledSendHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsSubmitForApprovalHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsUnsubscribeHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsUpdateTemplateHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsBatchSendHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsGetStatsHandler;
use MultiTenantSaas\Modules\Sms\Services\Tools\SmsListTemplatesHandler;

class SmsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'sms';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(SmsService::class);
    }

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('sms_create_template', 'Sms Create Template', 'Create template', SmsCreateTemplateHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '模板名称'], 'content' => ['type' => 'string', 'description' => '模板内容'], 'variables' => ['type' => 'array', 'description' => '变量列表'], 'type' => ['type' => 'string', 'description' => '模板类型']], 'required' => ['name', 'content']], 'sms', 'L2');
        $registry->register('sms_update_template', 'Sms Update Template', 'Update template', SmsUpdateTemplateHandler::class, ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer', 'description' => '模板ID'], 'name' => ['type' => 'string', 'description' => '模板名称'], 'content' => ['type' => 'string', 'description' => '模板内容'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['template_id']], 'sms', 'L2');
        $registry->register('sms_submit_for_approval', 'Sms Submit For Approval', 'Submit for approval', SmsSubmitForApprovalHandler::class, ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer', 'description' => '模板ID']], 'required' => ['template_id']], 'sms', 'L2');
        $registry->register('sms_render_content', 'Sms Render Content', 'Render content', SmsRenderContentHandler::class, ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer', 'description' => '模板ID'], 'variables' => ['type' => 'object', 'description' => '模板变量']], 'required' => ['template_id']], 'sms', 'L1');
        $registry->register('sms_scheduled_send', 'Sms Scheduled Send', 'Scheduled send', SmsScheduledSendHandler::class, ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer', 'description' => '模板ID'], 'phones' => ['type' => 'array', 'description' => '手机号列表'], 'scheduled_at' => ['type' => 'string', 'description' => '定时时间']], 'required' => ['template_id', 'phones', 'scheduled_at']], 'sms', 'L2');
        $registry->register('sms_get_batch_task', 'Sms Get Batch Task', 'Get batch task', SmsGetBatchTaskHandler::class, ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer', 'description' => '任务ID']], 'required' => ['task_id']], 'sms', 'L1');
        $registry->register('sms_cancel_batch_task', 'Sms Cancel Batch Task', 'Cancel batch task', SmsCancelBatchTaskHandler::class, ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer', 'description' => '任务ID']], 'required' => ['task_id']], 'sms', 'L2');
        $registry->register('sms_get_delivery_stats', 'Sms Get Delivery Stats', 'Get delivery stats', SmsGetDeliveryStatsHandler::class, ['type' => 'object', 'properties' => ['batch_task_id' => ['type' => 'integer', 'description' => '批次任务ID']], 'required' => ['batch_task_id']], 'sms', 'L1');
        $registry->register('sms_unsubscribe', 'Sms Unsubscribe', 'Unsubscribe', SmsUnsubscribeHandler::class, ['type' => 'object', 'properties' => ['phone' => ['type' => 'string', 'description' => '手机号'], 'reason' => ['type' => 'string', 'description' => '退订原因']], 'required' => ['phone']], 'sms', 'L2');
        $registry->register('sms_get_unsubscribes', 'Sms Get Unsubscribes', 'Get unsubscribes', SmsGetUnsubscribesHandler::class, ['type' => 'object', 'properties' => []], 'sms', 'L1');
        $registry->register('sms_list_templates', 'Sms List Templates', 'List sms templates', SmsListTemplatesHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'type' => ['type' => 'string', 'description' => '类型过滤']], 'required' => []], 'sms', 'L1');
        $registry->register('sms_get_stats', 'Sms Get Stats', 'Get overall sms stats', SmsGetStatsHandler::class, ['type' => 'object', 'properties' => ['start_date' => ['type' => 'string', 'description' => '开始日期 YYYY-MM-DD'], 'end_date' => ['type' => 'string', 'description' => '结束日期 YYYY-MM-DD']], 'required' => []], 'sms', 'L1');
        $registry->register('sms_batch_send', 'Sms Batch Send', 'Batch send sms by phone list', SmsBatchSendHandler::class, ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer', 'description' => '模板ID'], 'phones' => ['type' => 'array', 'description' => '手机号列表'], 'variables' => ['type' => 'object', 'description' => '模板变量']], 'required' => ['template_id', 'phones']], 'sms', 'L2');
    }
}
