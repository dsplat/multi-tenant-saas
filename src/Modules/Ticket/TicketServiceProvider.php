<?php

namespace MultiTenantSaas\Modules\Ticket;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

use MultiTenantSaas\Modules\Ticket\Services\TicketService;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketAddCommentHandler;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketAssignHandler;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketCreateHandler;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketGetCommentsHandler;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketGetHandler;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketListHandler;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketResolveHandler;
use MultiTenantSaas\Modules\Ticket\Services\Tools\TicketUpdateHandler;

class TicketServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ticket';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(TicketService::class);
    }

    protected function bootModule(): void
    {
        $this->registerTools();
        //
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('ticket_list', 'Ticket List', 'List', TicketListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'ticket', 'L1');
        $registry->register('ticket_get', 'Ticket Get', 'Get', TicketGetHandler::class, ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer', 'description' => '工单ID']], 'required' => ['ticket_id']], 'ticket', 'L1');
        $registry->register('ticket_create', 'Ticket Create', 'Create', TicketCreateHandler::class, ['type' => 'object', 'properties' => ['subject' => ['type' => 'string', 'description' => '主题'], 'content' => ['type' => 'string', 'description' => '内容'], 'priority' => ['type' => 'string', 'description' => '优先级']], 'required' => ['subject', 'content']], 'ticket', 'L2');
        $registry->register('ticket_update', 'Ticket Update', 'Update', TicketUpdateHandler::class, ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer', 'description' => '工单ID'], 'subject' => ['type' => 'string', 'description' => '主题'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['ticket_id']], 'ticket', 'L2');
        $registry->register('ticket_assign', 'Ticket Assign', 'Assign', TicketAssignHandler::class, ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer', 'description' => '工单ID'], 'operator_id' => ['type' => 'integer', 'description' => '处理人ID']], 'required' => ['ticket_id', 'operator_id']], 'ticket', 'L2');
        $registry->register('ticket_resolve', 'Ticket Resolve', 'Resolve', TicketResolveHandler::class, ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer', 'description' => '工单ID'], 'resolution' => ['type' => 'string', 'description' => '解决方案']], 'required' => ['ticket_id']], 'ticket', 'L2');
        $registry->register('ticket_add_comment', 'Ticket Add Comment', 'Add comment', TicketAddCommentHandler::class, ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer', 'description' => '工单ID'], 'content' => ['type' => 'string', 'description' => '评论内容']], 'required' => ['ticket_id', 'content']], 'ticket', 'L2');
        $registry->register('ticket_get_comments', 'Ticket Get Comments', 'Get comments', TicketGetCommentsHandler::class, ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer', 'description' => '工单ID']], 'required' => ['ticket_id']], 'ticket', 'L1');
    }
}
