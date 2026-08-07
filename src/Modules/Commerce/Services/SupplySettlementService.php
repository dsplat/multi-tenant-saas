<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Billing\Models\CreditTransaction;
use MultiTenantSaas\Modules\Billing\Notifications\CreditLowNotification;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 供货结算服务（P4，规避二清）
 *
 * 资金模型：租户向平台预存货款（平台自营收款，负债挂账 supply_prepay），
 * 平台划拨商品（supply_grants 批次化），用户向租户购买时租户自有商户号收款，
 * 平台仅做「扣预存 + 库存下发」记账，不经手租户→用户资金流。
 *
 * 财务口径：预存余额 = 预收账款（负债），settle 扣款时才确认收入；
 * 保证金 = 其他应付款（domain_deposit 独立台账，不与预存混用）。
 */
class SupplySettlementService
{
    public const ACCOUNT_TYPE_PREPAY = 'supply_prepay';

    public const ACCOUNT_TYPE_DOMAIN_DEPOSIT = 'domain_deposit';

    /** 预存低额告警默认阈值（分）：100 元 */
    public const DEFAULT_LOW_BALANCE_THRESHOLD = 10000;

    // ========== 账户 ==========

    /**
     * 获取或创建租户预存货款账户（不允许负余额）
     */
    public function prepayAccount(int $tenantId): CreditAccount
    {
        return $this->accountOfType($tenantId, self::ACCOUNT_TYPE_PREPAY);
    }

    /**
     * 获取或创建租户域名保证金账户
     */
    public function depositAccount(int $tenantId): CreditAccount
    {
        return $this->accountOfType($tenantId, self::ACCOUNT_TYPE_DOMAIN_DEPOSIT);
    }

    private function accountOfType(int $tenantId, string $type): CreditAccount
    {
        return CreditAccount::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_type' => $type, 'user_id' => null],
            [
                'balance' => 0,
                'gift_balance' => 0,
                'recharge_balance' => 0,
                'total_recharged' => 0,
                'total_consumed' => 0,
            ]
        );
    }

    // ========== 预存货款（admin 充值 / 结算扣款 / 补偿） ==========

    /**
     * 平台为租户充值预存货款（线下到账后人工记账）
     */
    public function rechargePrepay(int $tenantId, int $amountFen, int $operatorId, ?string $note = null): CreditTransaction
    {
        $account = $this->prepayAccount($tenantId);

        return DB::transaction(function () use ($account, $amountFen, $operatorId, $note) {
            $locked = CreditAccount::withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($account->getKey());

            return $locked->recharge($operatorId, $amountFen, $note ?? '预存货款充值');
        });
    }

    /**
     * 结算：扣预存 + 库存下发（原子事务 + 幂等）
     *
     * 时序：用户下单 lockStock → 租户侧收款确认 → 本方法扣预存并核销锁定量。
     * 幂等键：related_type + related_id（项目侧订单号），重复调用返回原流水。
     * 预存不足抛异常回滚，调用方应拒绝订单。
     */
    public function settle(SupplyGrant $grant, int $qty, string $refType, string $refId): CreditTransaction
    {
        if ($qty < 1) {
            throw new \InvalidArgumentException('结算数量必须大于 0');
        }

        return DB::transaction(function () use ($grant, $qty, $refType, $refId) {
            $account = CreditAccount::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->find($this->prepayAccount($grant->tenant_id)->getKey());

            // 幂等：同一业务引用只结算一次
            $existing = $this->findTx($account->getKey(), $refType, $refId, 'consume');
            if ($existing) {
                return $existing;
            }

            $locked = SupplyGrant::withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($grant->getKey());
            if (! $locked) {
                throw new DomainException('供给授权不存在');
            }
            if ($locked->locked_qty < $qty) {
                throw new DomainException("锁定库存不足：locked={$locked->locked_qty}，请求核销 {$qty}");
            }

            $cost = $locked->supplyPriceFen() * $qty;
            if ($cost > 0 && ! $account->hasEnoughBalance($cost)) {
                throw new DomainException("预存货款不足：余额 {$account->balance} 分，需 {$cost} 分");
            }

            if ($cost > 0) {
                $account->consume(
                    $cost,
                    $refType,
                    $refId,
                    sprintf('供货结算 grant#%d x%d（单价 %d 分）', $locked->grant_id, $qty, $locked->supplyPriceFen())
                );
            } else {
                // 免费划拨（supply_price=0）仅记账不扣款
                CreditTransaction::withoutGlobalScope(TenantScope::class)->create([
                    'account_id' => $account->getKey(),
                    'tenant_id' => $account->tenant_id,
                    'user_id' => null,
                    'type' => 'consume',
                    'amount' => 0,
                    'balance_after' => $account->balance,
                    'related_type' => $refType,
                    'related_id' => $refId,
                    'description' => sprintf('供货结算（免费划拨）grant#%d x%d', $locked->grant_id, $qty),
                ]);
            }

            $locked->decrement('locked_qty', $qty);

            $this->warnIfLowBalance($account);

            return $this->findTx($account->getKey(), $refType, $refId, 'consume');
        });
    }

    /**
     * 补偿：退还已结算货款 + 库存回补 remaining（退款/售后失败补偿）
     *
     * 幂等：同一业务引用只补偿一次。
     */
    public function compensate(SupplyGrant $grant, int $qty, string $refType, string $refId): ?CreditTransaction
    {
        return DB::transaction(function () use ($grant, $qty, $refType, $refId) {
            $account = CreditAccount::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->find($this->prepayAccount($grant->tenant_id)->getKey());

            $existing = $this->findTx($account->getKey(), $refType, $refId, 'refund');
            if ($existing) {
                return $existing;
            }

            $consumeTx = $this->findTx($account->getKey(), $refType, $refId, 'consume');
            if (! $consumeTx) {
                return null; // 未结算过，无需补偿
            }

            $refundAmount = abs($consumeTx->amount);

            // 手写退款流水：refund() 不带 related 且会冲减 total_consumed，
            // 这里同时挂业务引用保证幂等可查
            $account->increment('balance', $refundAmount);
            $refundTx = CreditTransaction::withoutGlobalScope(TenantScope::class)->create([
                'account_id' => $account->getKey(),
                'tenant_id' => $account->tenant_id,
                'user_id' => null,
                'type' => 'refund',
                'amount' => $refundAmount,
                'balance_after' => $account->balance,
                'related_type' => $refType,
                'related_id' => $refId,
                'description' => sprintf('供货补偿 grant#%d x%d', $grant->grant_id, $qty),
            ]);

            $locked = SupplyGrant::withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($grant->getKey());
            $locked?->increment('remaining_qty', $qty);

            return $refundTx;
        });
    }

    // ========== 库存锁定 ==========

    /**
     * 用户下单锁库存（防超卖），超时/取消用 unlockStock 释放
     */
    public function lockStock(SupplyGrant $grant, int $qty, string $refType, string $refId): void
    {
        DB::transaction(function () use ($grant, $qty) {
            $locked = SupplyGrant::withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($grant->getKey());
            if (! $locked) {
                throw new DomainException('供给授权不存在');
            }
            if (! $locked->isEffective()) {
                throw new DomainException('供给授权未生效（停供/过期）');
            }
            if (! $locked->isStockManaged()) {
                throw new DomainException('该授权为非库存型，无需锁库存');
            }
            if ($locked->remaining_qty < $qty) {
                throw new DomainException("可下发余量不足：remaining={$locked->remaining_qty}，请求锁定 {$qty}");
            }

            $locked->decrement('remaining_qty', $qty);
            $locked->increment('locked_qty', $qty);
        });

        Log::info('supply stock locked', [
            'grant_id' => $grant->getKey(),
            'qty' => $qty,
            'ref' => "{$refType}:{$refId}",
        ]);
    }

    /**
     * 释放锁定库存（订单取消/超时），回补 remaining
     */
    public function unlockStock(SupplyGrant $grant, int $qty): void
    {
        DB::transaction(function () use ($grant, $qty) {
            $locked = SupplyGrant::withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($grant->getKey());
            if (! $locked) {
                return;
            }
            $release = min($qty, $locked->locked_qty);
            if ($release < 1) {
                return;
            }
            $locked->decrement('locked_qty', $release);
            $locked->increment('remaining_qty', $release);
        });
    }

    // ========== 域名保证金 ==========

    /**
     * 锁定保证金（二级域名开通时）：租户缴纳，平台挂账其他应付款
     */
    public function lockDeposit(int $tenantId, int $amountFen, int $operatorId, ?string $note = null): CreditTransaction
    {
        return $this->depositLedger($tenantId, $amountFen, 'recharge', $note ?? '域名保证金锁定', $operatorId);
    }

    /**
     * 退还保证金（域名退出时）
     */
    public function releaseDeposit(int $tenantId, int $amountFen, int $operatorId, ?string $note = null): CreditTransaction
    {
        return $this->depositLedger($tenantId, -$amountFen, 'release', $note ?? '域名保证金退还', $operatorId);
    }

    /**
     * 违规扣除保证金（不退）
     */
    public function deductDeposit(int $tenantId, int $amountFen, int $operatorId, ?string $note = null): CreditTransaction
    {
        return $this->depositLedger($tenantId, -$amountFen, 'consume', $note ?? '域名保证金违规扣除', $operatorId);
    }

    private function depositLedger(int $tenantId, int $deltaFen, string $type, string $description, int $operatorId): CreditTransaction
    {
        if ($deltaFen === 0) {
            throw new \InvalidArgumentException('保证金金额必须大于 0');
        }

        return DB::transaction(function () use ($tenantId, $deltaFen, $type, $description, $operatorId) {
            $account = CreditAccount::withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->find($this->depositAccount($tenantId)->getKey());

            if ($deltaFen < 0 && $account->balance < abs($deltaFen)) {
                throw new DomainException("保证金余额不足：余额 {$account->balance} 分，需 {$deltaFen} 分");
            }

            $account->increment('balance', $deltaFen);
            if ($deltaFen > 0) {
                $account->increment('total_recharged', $deltaFen);
                $account->increment('recharge_balance', $deltaFen);
            } else {
                $account->increment('total_consumed', abs($deltaFen));
                $account->decrement('recharge_balance', abs($deltaFen));
            }

            return CreditTransaction::withoutGlobalScope(TenantScope::class)->create([
                'account_id' => $account->getKey(),
                'tenant_id' => $tenantId,
                'user_id' => $operatorId,
                'type' => $type,
                'amount' => $deltaFen,
                'balance_after' => $account->balance,
                'description' => $description,
            ]);
        });
    }

    // ========== 低额告警 ==========

    /**
     * 无作用域查流水（admin/系统上下文无 TenantContext，关联查询会被 fail-closed 拦截）
     */
    private function findTx(int $accountId, string $refType, string $refId, string $type): ?CreditTransaction
    {
        return CreditTransaction::withoutGlobalScope(TenantScope::class)
            ->where('account_id', $accountId)
            ->where('related_type', $refType)
            ->where('related_id', $refId)
            ->where('type', $type)
            ->first();
    }

    /**
     * 结算后预存低于阈值则告警（24h 去抖），沿用 CreditLowNotification
     */
    private function warnIfLowBalance(CreditAccount $account): void
    {
        $threshold = $account->auto_recharge_threshold > 0
            ? $account->auto_recharge_threshold
            : self::DEFAULT_LOW_BALANCE_THRESHOLD;

        if ($account->balance >= $threshold) {
            return;
        }
        if ($account->last_warning_at && $account->last_warning_at->gt(now()->subDay())) {
            return;
        }

        $account->forceFill(['last_warning_at' => now()])->save();

        Log::warning('supply prepay low balance', [
            'tenant_id' => $account->tenant_id,
            'balance' => $account->balance,
            'threshold' => $threshold,
        ]);

        try {
            $operators = Operator::query()
                ->whereHas('tenants', fn ($q) => $q->where('tenants.tenant_id', $account->tenant_id))
                ->get();
            foreach ($operators as $operator) {
                $operator->notify(new CreditLowNotification($account->balance, $threshold));
            }
        } catch (\Throwable $e) {
            Log::warning('supply prepay low-balance notify failed: ' . $e->getMessage());
        }
    }
}
