<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 订单退款成功事件（框架级）
 *
 * 本地退款状态变更后 dispatch；项目层订阅实现佣金冲销、埋点等扩展。
 */
class OrderRefunded
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int  $tenantId  租户ID
     * @param  string  $orderType  订单类型
     * @param  int  $orderId  订单主键ID
     * @param  string|null  $orderNo  订单编号
     * @param  int|null  $buyerUserId  买家用户ID
     * @param  float  $amount  退款现金金额
     * @param  int  $pointsAmount  返还虚拟资产数
     * @param  string|null  $reason  退款原因
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $orderType,
        public readonly int $orderId,
        public readonly ?string $orderNo,
        public readonly ?int $buyerUserId,
        public readonly float $amount,
        public readonly int $pointsAmount = 0,
        public readonly ?string $reason = null,
    ) {}
}
