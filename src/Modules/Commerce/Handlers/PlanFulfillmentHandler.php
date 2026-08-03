<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Handlers;

use MultiTenantSaas\Contracts\CommerceFulfillmentHandler;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;

/**
 * 套餐 SKU 履约 Handler
 *
 * payload: {plan_id: int, billing_cycle?: monthly|yearly}
 * 委托 SubscriptionService（订阅状态机 + 模块开通 + 历史记录）。
 */
class PlanFulfillmentHandler implements CommerceFulfillmentHandler
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    public function fulfill(CommerceOrderItem $item): void
    {
        $payload = $item->payload_snapshot ?? [];
        $planId = (int) ($payload['plan_id'] ?? 0);

        if ($planId <= 0) {
            throw new DomainException('套餐 SKU payload 缺少 plan_id');
        }

        $billingCycle = (string) ($payload['billing_cycle'] ?? 'monthly');

        $this->subscriptionService->subscribe(
            (int) $item->order->tenant_id,
            $planId,
            $billingCycle
        );
    }

    public function revoke(CommerceOrderItem $item): void
    {
        // 撤销 = 关闭自动续费（订阅到期自然降档，由 ProcessSubscriptions 接管）
        $this->subscriptionService->cancel((int) $item->order->tenant_id);
    }
}
