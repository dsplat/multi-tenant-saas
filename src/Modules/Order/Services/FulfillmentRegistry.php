<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Services;

use MultiTenantSaas\Modules\Order\Contracts\OrderFulfillmentHandlerContract;

/**
 * 订单履约注册表（按 order_items.item_type 分发）
 *
 * 模块/项目层在 Provider boot 中注册：
 *   app(FulfillmentRegistry::class)->register(new CourseFulfillmentHandler());
 */
class FulfillmentRegistry
{
    /** @var array<string, OrderFulfillmentHandlerContract> */
    protected array $handlers = [];

    public function register(OrderFulfillmentHandlerContract $handler): void
    {
        $this->handlers[$handler->itemType()] = $handler;
    }

    public function has(string $itemType): bool
    {
        return isset($this->handlers[$itemType]);
    }

    public function get(string $itemType): ?OrderFulfillmentHandlerContract
    {
        return $this->handlers[$itemType] ?? null;
    }

    /** @return array<string, OrderFulfillmentHandlerContract> */
    public function all(): array
    {
        return $this->handlers;
    }
}
