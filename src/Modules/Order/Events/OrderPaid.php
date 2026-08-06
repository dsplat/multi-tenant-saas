<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 订单支付成功事件（框架级）
 *
 * 任意订单支付成功后 dispatch；项目层订阅实现计佣、行为埋点等扩展。
 */
class OrderPaid
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int  $tenantId  租户ID
     * @param  string  $orderType  订单类型（registration/product/course/exchange）
     * @param  int  $orderId  订单主键ID
     * @param  string|null  $orderNo  订单编号
     * @param  int|null  $buyerUserId  买家用户ID（归因用）
     * @param  float  $amount  订单实付现金（计佣基数）
     * @param  array  $context  附加上下文（如 goods_id）
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $orderType,
        public readonly int $orderId,
        public readonly ?string $orderNo,
        public readonly ?int $buyerUserId,
        public readonly float $amount,
        public readonly array $context = [],
    ) {}
}
