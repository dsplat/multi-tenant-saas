<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Contracts;

use MultiTenantSaas\Modules\Order\Models\Order;

/**
 * 订单供货结算钩子（项目层实现，容器绑定后可选注入）
 *
 * 供货商品（平台供给 → 租户售卖）在订单链路内的库存与结算联动：
 * - onOrderCreated：下单事务内锁供给库存（不足抛异常 → 回滚下单）
 * - onOrderPaid：支付事务内、履约前扣预存结算（不足抛异常 → 回滚支付）
 * - onOrderRefunded：退款事务内补偿回补
 *
 * 实现方职责：按行解析供给归因（非供给行跳过）+ 幂等
 * （建议 refType='order', refId=order_no）。未绑定时订单链路零影响。
 */
interface OrderSupplyHookContract
{
    /** 下单锁库存（不足抛异常回滚下单事务） */
    public function onOrderCreated(Order $order): void;

    /** 支付确认后扣预存结算（不足抛异常回滚支付事务） */
    public function onOrderPaid(Order $order): void;

    /** 退款后补偿回补 */
    public function onOrderRefunded(Order $order): void;
}
