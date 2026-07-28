<?php

namespace MultiTenantSaas\Modules\Voting;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingCreateHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingGetRecordsHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingGetStatisticsHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingUpdateHandler;
use MultiTenantSaas\Modules\Voting\Services\VotingService;

class VotingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'voting';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(VotingService::class);
    }

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('voting_create', 'Voting Create', 'Create', VotingCreateHandler::class, ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'description' => '投票标题'], 'options' => ['type' => 'array', 'description' => '选项列表'], 'start_at' => ['type' => 'string', 'description' => '开始时间'], 'end_at' => ['type' => 'string', 'description' => '结束时间']], 'required' => ['title', 'options']], 'voting', 'L2');
        $registry->register('voting_update', 'Voting Update', 'Update', VotingUpdateHandler::class, ['type' => 'object', 'properties' => ['vote_id' => ['type' => 'integer', 'description' => '投票ID'], 'title' => ['type' => 'string', 'description' => '标题'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['vote_id']], 'voting', 'L2');
        $registry->register('voting_get_records', 'Voting Get Records', 'Get records', VotingGetRecordsHandler::class, ['type' => 'object', 'properties' => ['vote_id' => ['type' => 'integer', 'description' => '投票ID']], 'required' => ['vote_id']], 'voting', 'L1');
        $registry->register('voting_get_statistics', 'Voting Get Statistics', 'Get statistics', VotingGetStatisticsHandler::class, ['type' => 'object', 'properties' => ['vote_id' => ['type' => 'integer', 'description' => '投票ID']], 'required' => ['vote_id']], 'voting', 'L1');
    }
}
