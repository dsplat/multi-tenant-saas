<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Services;

use MultiTenantSaas\Modules\Order\Contracts\OrderFulfillmentHandlerContract;

/**
 * 订单履约注册表（按 order_items.entity_type 分发）
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
        $this->handlers[$handler->entityType()] = $handler;
    }

    public function has(string $entityType): bool
    {
        return isset($this->handlers[$entityType]);
    }

    public function get(string $entityType): ?OrderFulfillmentHandlerContract
    {
        return $this->handlers[$entityType] ?? null;
    }

    /** @return array<string, OrderFulfillmentHandlerContract> */
    public function all(): array
    {
        return $this->handlers;
    }
}
