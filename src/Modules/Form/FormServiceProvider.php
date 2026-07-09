<?php

namespace MultiTenantSaas\Modules\Form;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Form\Services\FormBuilderService;

class FormServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'form';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(FormBuilderService::class);
    }
}
