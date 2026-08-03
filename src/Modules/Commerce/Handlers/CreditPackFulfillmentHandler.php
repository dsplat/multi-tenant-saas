<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Handlers;

use MultiTenantSaas\Contracts\CommerceFulfillmentHandler;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * AI 积分包履约 Handler
 *
 * payload: {credits: int, gift_credits?: int, gift_expire_days?: int}
 * 复用 CreditAccount::recharge/gift（充值额/赠送额分账）。
 * 积分包禁止退款（已决策）：revoke 直接拒绝。
 */
class CreditPackFulfillmentHandler implements CommerceFulfillmentHandler
{
    public function fulfill(CommerceOrderItem $item): void
    {
        $payload = $item->payload_snapshot ?? [];
        $credits = (int) ($payload['credits'] ?? 0);

        if ($credits <= 0) {
            throw new DomainException('积分包 SKU payload 缺少 credits');
        }

        $tenantId = (int) $item->order->tenant_id;
        $operatorId = (int) ($item->order->operator_id ?? 0);

        $account = CreditAccount::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => null],
            [
                'account_type' => 'enterprise',
                'balance' => 0,
                'gift_balance' => 0,
                'recharge_balance' => 0,
                'total_recharged' => 0,
                'total_consumed' => 0,
            ]
        );

        for ($i = 0; $i < $item->qty; $i++) {
            $account->recharge($operatorId, $credits, "积分包购买（订单 {$item->order->order_no}）");

            $giftCredits = (int) ($payload['gift_credits'] ?? 0);
            if ($giftCredits > 0) {
                $giftExpireDays = (int) ($payload['gift_expire_days'] ?? 365);
                $account->gift($operatorId, $giftCredits, $giftExpireDays, "积分包赠送（订单 {$item->order->order_no}）");
            }
        }
    }

    public function revoke(CommerceOrderItem $item): void
    {
        // 积分包禁止退款（已决策）：已消耗不可回收，履约不可逆
        throw new DomainException('积分包不支持撤销/退款');
    }
}
