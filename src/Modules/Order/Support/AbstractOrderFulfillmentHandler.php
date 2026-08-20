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
}
