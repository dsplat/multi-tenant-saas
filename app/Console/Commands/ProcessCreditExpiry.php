<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\SubscriptionService;
use MultiTenantSaas\Services\CreditService;
use MultiTenantSaas\Models\CreditAccount;

class ProcessCreditExpiry extends Command
{
    protected $signature = 'credits:process-expiry';
    protected $description = '处理积分过期和自动充值';

    public function handle(): int
    {
        // 处理过期积分
        $expiredCount = $this->processExpiredCredits();
        $this->info("处理过期积分: {$expiredCount} 条");

        // 处理低余额预警
        $warnCount = $this->processLowBalanceWarning();
        $this->info("发送低余额预警: {$warnCount} 条");

        return self::SUCCESS;
    }

    private function processExpiredCredits(): int
    {
        $count = 0;
        $accounts = CreditAccount::where('expires_at', '<', now())
            ->where('balance', '>', 0)
            ->get();

        foreach ($accounts as $account) {
            $expiredAmount = $account->balance;
            $account->balance = 0;
            $account->expired_total += $expiredAmount;
            $account->save();

            // 记录过期交易
            \MultiTenantSaas\Models\CreditTransaction::create([
                'tenant_id' => $account->tenant_id,
                'account_id' => $account->id,
                'type' => 'expire',
                'amount' => -$expiredAmount,
                'balance_after' => 0,
                'description' => '积分过期',
            ]);

            $count++;
        }

        return $count;
    }

    private function processLowBalanceWarning(): int
    {
        $count = 0;
        $threshold = config('tenancy.credit_warning_threshold', 100);

        $accounts = CreditAccount::where('balance', '>', 0)
            ->where('balance', '<=', $threshold)
            ->where('last_warning_at', null)
            ->orWhere(function ($q) use ($threshold) {
                $q->where('balance', '>', 0)
                  ->where('balance', '<=', $threshold)
                  ->where('last_warning_at', '<', now()->subDays(3));
            })
            ->get();

        foreach ($accounts as $account) {
            $tenant = $account->tenant;
            if ($tenant && $tenant->isActive()) {
                \MultiTenantSaas\Services\NotificationService::notifyCreditLow(
                    $tenant,
                    $account->balance,
                    $threshold
                );
                $account->last_warning_at = now();
                $account->save();
                $count++;
            }
        }

        return $count;
    }
}
