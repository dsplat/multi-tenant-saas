<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\SubscriptionService;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';
    protected $description = '处理订阅：发送到期提醒 + 过期降级 + 自动续费';

    public function handle(): int
    {
        $service = new SubscriptionService();

        $expiringCount = $service->processExpiringSubscriptions();
        $this->info("发送到期提醒: {$expiringCount} 个租户");

        $expiredCount = $service->processExpiredSubscriptions();
        $this->info("处理过期订阅: {$expiredCount} 个租户");

        return self::SUCCESS;
    }
}
