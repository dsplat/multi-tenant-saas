<?php

namespace MultiTenantSaas\Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Billing\Models\PaymentOrder;
use MultiTenantSaas\Modules\Billing\Services\DunningService;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Monitoring\Services\TrialService;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = '处理订阅：发送到期提醒 + 过期降级 + 自动续费 + 催款 + 试用期处理';

    public function handle(): int
    {
        $service = new SubscriptionService;

        $expiringCount = $service->processExpiringSubscriptions();
        $this->info("发送到期提醒: {$expiringCount} 个租户");

        $expiredCount = $service->processExpiredSubscriptions();
        $this->info("处理过期订阅: {$expiredCount} 个租户");

        $dunningRetryCount = $this->processFailedPayments();
        $this->info("处理催款重试: {$dunningRetryCount} 个租户");

        $trialService = new TrialService;

        $expiringTrialCount = $trialService->processExpiringTrials();
        $this->info("处理试用到期提醒: {$expiringTrialCount} 个租户");

        $expiredTrialCount = $trialService->processExpiredTrials();
        $this->info("处理试用到期: {$expiredTrialCount} 个租户");

        return self::SUCCESS;
    }

    /**
     * 遍历存在失败支付的租户，按催款策略重试或暂停
     */
    private function processFailedPayments(): int
    {
        $count = 0;

        $tenantIds = PaymentOrder::where('status', 'failed')
            ->select('tenant_id')
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $result = DunningService::processFailedPayment((int) $tenantId);

            if ($result['action'] === 'retry') {
                $count++;
                Log::info('Dunning: retry scheduled', [
                    'tenant_id' => $tenantId,
                    'next_retry_at' => $result['next_retry_at']?->toDateTimeString(),
                ]);
            } elseif ($result['action'] === 'suspend') {
                DunningService::suspendTenant((int) $tenantId);
                $count++;
                Log::warning('Dunning: tenant suspended after grace period', [
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        return $count;
    }
}
