<?php

namespace MultiTenantSaas\Modules\Conversation;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Conversation\Services\ConversationService;
use MultiTenantSaas\Modules\Conversation\Services\ConversationSummaryService;

class ConversationServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'conversation';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(ConversationService::class);
        $this->app->singleton(ConversationSummaryService::class);
    }
}
