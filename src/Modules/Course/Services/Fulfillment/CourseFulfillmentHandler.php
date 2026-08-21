<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services\Fulfillment;

use MultiTenantSaas\Modules\Course\Models\CourseEntitlement;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Support\AbstractOrderFulfillmentHandler;

/**
 * 课程履约处理器
 *
 * 订单级 entity_type='course' 支付确认后，幂等授予课程权益；
 * Package 拆解场景下身份从组成项解析（见 AbstractOrderFulfillmentHandler）。
 * 由 CourseServiceProvider boot 注册进 Order 模块 FulfillmentRegistry。
 */
class CourseFulfillmentHandler extends AbstractOrderFulfillmentHandler
{
    public function entityType(): string
    {
        return 'course';
    }

    public function fulfill(Order $order, mixed $item): void
    {
        $courseId = $this->resolveEntityId($order, $item);

        if (! $courseId || ! $order->user_id) {
            return;
        }

        // 幂等授予课程权益
        CourseEntitlement::firstOrCreate(
            [
                'tenant_id' => (int) $order->tenant_id,
                'user_id'   => (int) $order->user_id,
                'course_id' => (int) $courseId,
            ],
            ['order_id' => $order->order_id]
        );
    }

    /**
     * 逆向履约：退款时撤销课程权益（幂等：不存在时零副作用）
     */
    public function revoke(Order $order, mixed $item): void
    {
        $courseId = $this->resolveEntityId($order, $item);

        if (! $courseId || ! $order->user_id) {
            return;
        }

        CourseEntitlement::where('tenant_id', (int) $order->tenant_id)
            ->where('user_id', (int) $order->user_id)
            ->where('course_id', (int) $courseId)
            ->delete();
    }
}
