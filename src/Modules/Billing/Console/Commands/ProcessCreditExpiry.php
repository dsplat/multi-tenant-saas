<?php

namespace MultiTenantSaas\Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\CreditTransaction;
use MultiTenantSaas\Models\FinancialRecord;
use MultiTenantSaas\Services\NotificationService;

class ProcessCreditExpiry extends Command
{
    protected $signature = 'credits:process-expiry';

    protected $description = '处理积分过期、低余额预警和自动充值';

    public function handle(): int
    {
        // 处理账户级过期积分
        $expiredCount = $this->processExpiredCredits();
        $this->info(trans('credit.process_expired_credits', ['count' => $expiredCount]));

        // 处理交易级过期积分（赠送积分按笔过期）
        $txnExpiredCount = $this->processTransactionLevelExpiry();
        $this->info(trans('credit.process_transaction_expiry', ['count' => $txnExpiredCount]));

        // 处理低余额预警
        $warnCount = $this->processLowBalanceWarning();
        $this->info(trans('credit.process_low_balance', ['count' => $warnCount]));

        // 处理自动充值
        $rechargeCount = $this->processAutoRecharge();
        $this->info(trans('credit.process_auto_recharge', ['count' => $rechargeCount]));

        return self::SUCCESS;
    }

    /**
     * 账户级过期处理
     */
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

            CreditTransaction::create([
                'account_id' => $account->credit_account_id,
                'tenant_id' => $account->tenant_id,
                'type' => 'expire',
                'amount' => -$expiredAmount,
                'balance_after' => 0,
                'description' => trans('credit.account_expired'),
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * 交易级过期处理 - 按笔处理赠送/充值积分的过期
     * 场景：某些充值赠送的积分有独立过期时间，需按笔扣减
     */
    private function processTransactionLevelExpiry(): int
    {
        $count = 0;

        // 查找已过期但未标记 expired 的正向交易（充值/赠送）
        $transactions = CreditTransaction::whereIn('type', ['recharge', 'gift', 'refund'])
            ->where('amount', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('expired', false)
            ->get();

        // 按 tenant_id 分组处理
        $grouped = $transactions->groupBy('tenant_id');

        foreach ($grouped as $tenantId => $txns) {
            $account = CreditAccount::where('tenant_id', $tenantId)->first();
            if (! $account) {
                continue;
            }

            $totalExpired = 0;

            foreach ($txns as $txn) {
                $totalExpired += $txn->amount;

                // 标记为已过期
                $txn->expired = true;
                $txn->save();

                // 记录过期交易
                CreditTransaction::create([
                    'account_id' => $account->credit_account_id,
                    'tenant_id' => $tenantId,
                    'type' => 'expire',
                    'amount' => -$txn->amount,
                    'balance_after' => max(0, $account->balance - $totalExpired),
                    'description' => trans('credit.gift_expired', ['id' => $txn->transaction_id]),
                    'metadata' => ['original_transaction_id' => $txn->transaction_id],
                ]);

                $count++;
            }

            // 扣减账户余额（不能小于 0）
            if ($totalExpired > 0) {
                $account->balance = max(0, $account->balance - $totalExpired);
                $account->expired_total += $totalExpired;
                $account->save();
            }
        }

        return $count;
    }

    /**
     * 自动充值触发
     * 当账户余额低于 auto_recharge_threshold 且启用了 auto_recharge_enabled 时
     * 自动创建充值订单
     */
    private function processAutoRecharge(): int
    {
        $count = 0;

        $accounts = CreditAccount::where('auto_recharge_enabled', true)
            ->where('balance', '<=', function ($q) {
                $q->from('credit_accounts')
                    ->selectRaw('auto_recharge_threshold')
                    ->whereColumn('credit_account_id', 'credit_accounts.credit_account_id');
            })
            ->get();

        foreach ($accounts as $account) {
            // 确保余额确实低于阈值
            if ($account->balance > $account->auto_recharge_threshold) {
                continue;
            }

            $rechargeAmount = $account->auto_recharge_amount;
            $orderNo = 'ARC-' . date('YmdHis') . '-' . $account->tenant_id;

            try {
                // 创建财务记录
                FinancialRecord::create([
                    'tenant_id' => $account->tenant_id,
                    'type' => 'credit_recharge',
                    'amount' => $rechargeAmount,
                    'status' => 'pending',
                    'metadata' => [
                        'order_no' => $orderNo,
                        'auto_recharge' => true,
                        'account_id' => $account->credit_account_id,
                    ],
                ]);

                // TODO: 调用 PayService 发起自动扣款
                // 目前仅记录订单，实际扣款需对接支付网关

                Log::info(trans('credit.auto_recharge_triggered'), [
                    'tenant_id' => $account->tenant_id,
                    'order_no' => $orderNo,
                    'amount' => $rechargeAmount,
                    'current_balance' => $account->balance,
                    'threshold' => $account->auto_recharge_threshold,
                ]);

                // 发送通知
                $tenant = $account->tenant;
                if ($tenant && $tenant->isActive()) {
                    NotificationService::sendToTenantAdmins(
                        $account->tenant_id,
                        trans('credit.auto_recharge_triggered'),
                        trans('credit.auto_recharge_detail', [
                            'balance' => $account->balance,
                            'threshold' => $account->auto_recharge_threshold,
                            'amount' => $rechargeAmount,
                        ]),
                        'info',
                        url('/console/credits')
                    );
                }

                $count++;
            } catch (\Exception $e) {
                Log::error(trans('credit.recharge_failed'), [
                    'tenant_id' => $account->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function processLowBalanceWarning(): int
    {
        $count = 0;
        $threshold = config('tenancy.credit_warning_threshold', 100);

        $accounts = CreditAccount::where('balance', '>', 0)
            ->where('balance', '<=', $threshold)
            ->where(function ($q) {
                $q->whereNull('last_warning_at')
                    ->orWhere('last_warning_at', '<', now()->subDays(3));
            })
            ->get();

        foreach ($accounts as $account) {
            $tenant = $account->tenant;
            if ($tenant && $tenant->isActive()) {
                NotificationService::notifyCreditLow(
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
