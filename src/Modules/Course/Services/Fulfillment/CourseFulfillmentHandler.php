<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services\Fulfillment;

use MultiTenantSaas\Modules\Course\Models\CourseEntitlement;
use MultiTenantSaas\Modules\Order\Contracts\OrderFulfillmentHandlerContract;
use MultiTenantSaas\Modules\Order\Models\Order;

/**
 * 课程履约处理器
 *
 * 订单行 entity_type='course' 时，幂等授予课程权益。
 * 由 CourseServiceProvider boot 注册进 Order 模块 FulfillmentRegistry。
 */
class CourseFulfillmentHandler implements OrderFulfillmentHandlerContract
{
    public function entityType(): string
    {
        return 'course';
    }

    public function fulfill(Order $order, mixed $item): void
    {
        if (! $item->entity_id || ! $order->user_id) {
            return;
        }

        // 幂等授予课程权益
        CourseEntitlement::firstOrCreate(
            [
                'tenant_id' => (int) $order->tenant_id,
                'user_id'   => (int) $order->user_id,
                'course_id' => (int) $item->entity_id,
            ],
            ['order_id' => $order->order_id]
        );
    }
}
