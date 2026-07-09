<?php

namespace MultiTenantSaas\Modules\Event;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\EventBusService;

class EventServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'event';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(EventBusService::class);
    }
}
