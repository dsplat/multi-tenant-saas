<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Order\Services\FulfillmentRegistry;

/**
 * Order 模块（统一订单中心）
 *
 * 一切交易皆订单：orders + order_items + consumption_records。
 * - 支付委托 Pay 模块（TradePayService）
 * - 履约委托 FulfillmentRegistry（Course/项目层注册 handler）
 * - 事件：OrderPaid / OrderRefunded（项目层订阅计佣、埋点等）
 */
class OrderServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'order';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(FulfillmentRegistry::class);
    }
}
