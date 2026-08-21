<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Contracts;

use MultiTenantSaas\Modules\Order\Models\Order;

/**
 * 订单履约处理器契约
 *
 * 支付确认后（订单事务内）按订单行 entity_type 分发履约：
 * - Course 模块注册 'course' handler（授予课程权益）
 * - 项目层可注册 'points_product' / 'ticket' 等 handler（积分兑换、活动票）
 */
interface OrderFulfillmentHandlerContract
{
    /** 处理的订单行实体类型（order_items.entity_type，取 EntityTypes 白名单值） */
    public function entityType(): string;

    /**
     * 履约单个订单行（处于订单支付事务内，异常将回滚支付）
     */
    public function fulfill(Order $order, mixed $item): void;

    /**
     * 逆向履约（退款时撤销 fulfill 授予的权益，处于退款事务内）
     *
     * 必须幂等：重复退款/重复回调不得重复撤销副作用（如重复递减计数）。
     */
    public function revoke(Order $order, mixed $item): void;
}
