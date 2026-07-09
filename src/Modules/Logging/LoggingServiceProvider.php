<?php

namespace MultiTenantSaas\Modules\Logging;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\StructuredLogService;

class LoggingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'logging';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(StructuredLogService::class);
    }
}
