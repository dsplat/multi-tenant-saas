<?php

namespace MultiTenantSaas\Modules\Demo;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class DemoServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'demo';

    protected function registerModuleBindings(): void
    {
        // $this->app->singleton(YourService::class);
    }

    protected function bootModule(): void
    {
        //
    }

    protected function registerModuleCommands(): void
    {
        // if ($this->app->runningInConsole()) {
        //     $this->commands([YourCommand::class]);
        // }
    }
}
