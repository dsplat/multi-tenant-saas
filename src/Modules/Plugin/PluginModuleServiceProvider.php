<?php

namespace MultiTenantSaas\Modules\Plugin;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\PluginService;

class PluginModuleServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'plugin';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(PluginService::class);
    }
}
