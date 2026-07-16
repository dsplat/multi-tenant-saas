<?php

namespace MultiTenantSaas\Modules\Ticket;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Ticket\Services\TicketService;

class TicketServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ticket';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(TicketService::class);
    }

    protected function bootModule(): void
    {
        //
    }
}
