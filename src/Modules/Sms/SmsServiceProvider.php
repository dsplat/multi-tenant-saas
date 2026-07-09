<?php

namespace MultiTenantSaas\Modules\Sms;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Sms\Services\SmsService;

class SmsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'sms';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(SmsService::class);
    }
}
