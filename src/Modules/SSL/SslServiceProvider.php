<?php

namespace MultiTenantSaas\Modules\SSL;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\SSL\Commands\AutoIssueSsl;

class SSLServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ssl';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AutoIssueSsl::class,
            ]);
        }
    }
}
