<?php

namespace MultiTenantSaas\Modules\Notification;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class NotificationServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'notification';

    protected function registerModuleBindings(): void
    {
        //
    }
}
