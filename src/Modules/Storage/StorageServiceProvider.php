<?php

namespace MultiTenantSaas\Modules\Storage;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class StorageServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'storage';

    protected function registerModuleBindings(): void
    {
        //
    }
}
