<?php

namespace MultiTenantSaas\Modules\Voting;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Voting\Services\VotingService;

class VotingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'voting';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(VotingService::class);
    }
}
