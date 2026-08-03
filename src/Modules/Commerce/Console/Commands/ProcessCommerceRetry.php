<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Commerce\Services\CommerceFulfillmentService;

/**
 * 商业化履约补偿（建议 cron 每 5 分钟执行）
 *
 * - 重试履约失败项（retry_count < 3）
 * - 处理过期模块权益（关闭无其他有效权益的模块开关）
 * - 处理过期供给授权（置 expired 并联动项目侧实例）
 */
class ProcessCommerceRetry extends Command
{
    protected $signature = 'commerce:retry';

    protected $description = '商业化履约补偿：重试失败订单项 + 处理过期权益与供给授权';

    public function handle(CommerceFulfillmentService $service): int
    {
        $fulfilled = $service->retryFailed();
        $this->info("履约补偿成功: {$fulfilled} 个订单项");

        $expired = $service->processExpiredEntitlements();
        $this->info("处理过期权益: {$expired} 条");

        $expiredGrants = $service->processExpiredGrants();
        $this->info("处理过期供给授权: {$expiredGrants} 条");

        return self::SUCCESS;
    }
}
