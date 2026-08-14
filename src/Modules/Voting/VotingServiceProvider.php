<?php

namespace MultiTenantSaas\Modules\Voting;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingCreateHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingGetRecordsHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingGetStatisticsHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingUpdateHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingCastHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingListHandler;
use MultiTenantSaas\Modules\Voting\Services\Tools\VotingRankingHandler;
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
        $registry->register('voting_list', 'Voting List', 'List votings', VotingListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']], 'required' => []], 'voting', 'L1');
        $registry->register('voting_ranking', 'Voting Ranking', 'Get voting ranking', VotingRankingHandler::class, ['type' => 'object', 'properties' => ['vote_id' => ['type' => 'integer', 'description' => '投票ID']], 'required' => ['vote_id']], 'voting', 'L1');
        $registry->register('voting_cast', 'Voting Cast', 'Cast vote for user', VotingCastHandler::class, ['type' => 'object', 'properties' => ['vote_id' => ['type' => 'integer', 'description' => '投票ID'], 'option_ids' => ['type' => 'array', 'description' => '选项ID列表'], 'user_id' => ['type' => 'integer', 'description' => '投票用户ID']], 'required' => ['vote_id', 'option_ids', 'user_id']], 'voting', 'L2');
    }
}
