<?php

namespace MultiTenantSaas\Modules\Lottery;

use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryAddBlacklistHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryAddPrizeHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryCreateActivityHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryDeletePrizeHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryGetActivityHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryGetBlacklistHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryGetDrawStatsHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryGetPrizesHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryGetUserDrawLogsHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryGetWinLogsHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryRemoveBlacklistHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryUpdateActivityHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryUpdatePrizeHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryUpdateStatusHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryDrawHandler;
use MultiTenantSaas\Modules\Lottery\Services\Tools\LotteryListHandler;

class LotteryServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'lottery';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(LotteryService::class, fn ($app) => new LotteryService($app->make(TenantContextContract::class)));
    }

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('lottery_create_activity', 'Lottery Create Activity', 'Create activity', LotteryCreateActivityHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '活动名称'], 'type' => ['type' => 'string', 'description' => '活动类型'], 'start_at' => ['type' => 'string', 'description' => '开始时间'], 'end_at' => ['type' => 'string', 'description' => '结束时间']], 'required' => ['name']], 'lottery', 'L2');
        $registry->register('lottery_update_activity', 'Lottery Update Activity', 'Update activity', LotteryUpdateActivityHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID'], 'name' => ['type' => 'string', 'description' => '活动名称'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['activity_id']], 'lottery', 'L2');
        $registry->register('lottery_get_activity', 'Lottery Get Activity', 'Get activity', LotteryGetActivityHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID']], 'required' => ['activity_id']], 'lottery', 'L1');
        $registry->register('lottery_update_status', 'Lottery Update Status', 'Update status', LotteryUpdateStatusHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['activity_id', 'status']], 'lottery', 'L2');
        $registry->register('lottery_add_prize', 'Lottery Add Prize', 'Add prize', LotteryAddPrizeHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID'], 'name' => ['type' => 'string', 'description' => '奖品名称'], 'probability' => ['type' => 'number', 'description' => '中奖概率'], 'stock' => ['type' => 'integer', 'description' => '库存']], 'required' => ['activity_id', 'name']], 'lottery', 'L2');
        $registry->register('lottery_update_prize', 'Lottery Update Prize', 'Update prize', LotteryUpdatePrizeHandler::class, ['type' => 'object', 'properties' => ['prize_id' => ['type' => 'integer', 'description' => '奖品ID'], 'name' => ['type' => 'string', 'description' => '奖品名称'], 'probability' => ['type' => 'number', 'description' => '概率'], 'stock' => ['type' => 'integer', 'description' => '库存']], 'required' => ['prize_id']], 'lottery', 'L2');
        $registry->register('lottery_delete_prize', 'Lottery Delete Prize', 'Delete prize', LotteryDeletePrizeHandler::class, ['type' => 'object', 'properties' => ['prize_id' => ['type' => 'integer', 'description' => '奖品ID']], 'required' => ['prize_id']], 'lottery', 'L2');
        $registry->register('lottery_get_prizes', 'Lottery Get Prizes', 'Get prizes', LotteryGetPrizesHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID']], 'required' => ['activity_id']], 'lottery', 'L1');
        $registry->register('lottery_get_draw_stats', 'Lottery Get Draw Stats', 'Get draw stats', LotteryGetDrawStatsHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID']], 'required' => ['activity_id']], 'lottery', 'L1');
        $registry->register('lottery_get_win_logs', 'Lottery Get Win Logs', 'Get win logs', LotteryGetWinLogsHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID']], 'required' => ['activity_id']], 'lottery', 'L1');
        $registry->register('lottery_get_user_draw_logs', 'Lottery Get User Draw Logs', 'Get user draw logs', LotteryGetUserDrawLogsHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID'], 'user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['activity_id']], 'lottery', 'L1');
        $registry->register('lottery_get_blacklist', 'Lottery Get Blacklist', 'Get blacklist', LotteryGetBlacklistHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID']], 'required' => ['activity_id']], 'lottery', 'L1');
        $registry->register('lottery_add_blacklist', 'Lottery Add Blacklist', 'Add blacklist', LotteryAddBlacklistHandler::class, ['type' => 'object', 'properties' => ['tenant_id' => ['type' => 'integer', 'description' => '租户ID'], 'activity_id' => ['type' => 'integer', 'description' => '活动ID'], 'identifier_type' => ['type' => 'string', 'description' => '标识类型'], 'identifier' => ['type' => 'string', 'description' => '标识值'], 'reason' => ['type' => 'string', 'description' => '原因']], 'required' => ['activity_id', 'identifier_type', 'identifier']], 'lottery', 'L2');
        $registry->register('lottery_remove_blacklist', 'Lottery Remove Blacklist', 'Remove blacklist', LotteryRemoveBlacklistHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID'], 'identifier_type' => ['type' => 'string', 'description' => '标识类型'], 'identifier' => ['type' => 'string', 'description' => '标识值']], 'required' => ['activity_id', 'identifier_type', 'identifier']], 'lottery', 'L2');
        $registry->register('lottery_list', 'Lottery List', 'List activities', LotteryListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤']], 'required' => []], 'lottery', 'L1');
        $registry->register('lottery_draw', 'Lottery Draw', 'Draw for user', LotteryDrawHandler::class, ['type' => 'object', 'properties' => ['activity_id' => ['type' => 'integer', 'description' => '活动ID'], 'user_id' => ['type' => 'integer', 'description' => '用户ID（可选）']], 'required' => ['activity_id']], 'lottery', 'L2');
    }
}
