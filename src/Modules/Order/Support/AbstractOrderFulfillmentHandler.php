<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Support;

use MultiTenantSaas\Modules\Order\Contracts\OrderFulfillmentHandlerContract;
use MultiTenantSaas\Modules\Order\Models\Order;

/**
 * 履约 Handler 基类：叶子实体身份解析
 *
 * 两种履约上下文：
 * - 普通订单：$item 为 OrderItem（无实体身份字段）→ 身份取订单级主实体 $order->entity_id
 * - Package 拆解：$item 为 PackageItem（item_type/item_id 即叶子实体）→ 身份取组成项
 */
abstract class AbstractOrderFulfillmentHandler implements OrderFulfillmentHandlerContract
{
    /**
     * 解析叶子实体 ID（package 拆解场景优先取组成项，否则订单级主实体）
     */
    protected function resolveEntityId(Order $order, mixed $item): ?string
    {
        if (is_object($item)
            && isset($item->item_type, $item->item_id)
            && (string) $item->item_type === $this->entityType()
        ) {
            return (string) $item->item_id;
        }

        return $order->entity_id !== null ? (string) $order->entity_id : null;
    }

    /**
     * 逆向履约默认空实现（H3）
     *
     * 无副作用权益的 handler（如 Package 拆解）无需撤销，沿用默认；
     * 授予持久权益的 handler（课程/活动等）应覆写本方法做幂等撤销。
     */
    public function revoke(Order $order, mixed $item): void
    {
        // 默认无副作用，子类按需覆写
    }
}
