<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Logistics\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Logistics\Models\Shipment;
use MultiTenantSaas\Modules\Order\Models\Order;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 发货单服务（仅登记与状态流转，不对接第三方快递 API）
 */
class ShipmentService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 登记发货单（按订单号关联）
     *
     * $data: order_no(required), carrier?, tracking_no?, receiver_name?,
     *        receiver_phone?, receiver_address?, items?, remark?
     */
    public function createShipment(int $tenantId, array $data): Shipment
    {
        TenantContext::setTenantId((string) $tenantId);

        $order = Order::where('order_no', $data['order_no'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $order) {
            throw new NotFoundHttpException("Order [{$data['order_no']}] not found");
        }

        if ($order->status !== Order::STATUS_PAID) {
            throw new UnprocessableEntityHttpException("Order status '{$order->status}' does not allow shipment");
        }

        return Shipment::create([
            'shipment_id'      => $this->idGenerator->generate(),
            'tenant_id'        => $tenantId,
            'order_id'         => $order->order_id,
            'order_no'         => $order->order_no,
            'user_id'          => $order->user_id,
            'carrier'          => $data['carrier'] ?? null,
            'tracking_no'      => $data['tracking_no'] ?? null,
            'status'           => Shipment::STATUS_PENDING,
            'receiver_name'    => $data['receiver_name'] ?? null,
            'receiver_phone'   => $data['receiver_phone'] ?? null,
            'receiver_address' => $data['receiver_address'] ?? null,
            'items'            => $data['items'] ?? null,
            'remark'           => $data['remark'] ?? null,
        ]);
    }

    /** 发货：填入承运方/运单号并置 shipped */
    public function ship(int $tenantId, int $shipmentId, ?string $carrier, ?string $trackingNo): Shipment
    {
        $shipment = $this->getShipment($tenantId, $shipmentId);

        if (! $shipment->canShip()) {
            throw new UnprocessableEntityHttpException("Shipment status '{$shipment->status}' does not allow shipping");
        }

        $shipment->update(array_filter([
            'carrier'     => $carrier,
            'tracking_no' => $trackingNo,
        ], fn ($v) => $v !== null) + [
            'status'     => Shipment::STATUS_SHIPPED,
            'shipped_at' => now(),
        ]);

        return $shipment->fresh();
    }

    /** 签收 */
    public function deliver(int $tenantId, int $shipmentId): Shipment
    {
        $shipment = $this->getShipment($tenantId, $shipmentId);

        if (! $shipment->canDeliver()) {
            throw new UnprocessableEntityHttpException("Shipment status '{$shipment->status}' does not allow delivery");
        }

        $shipment->update([
            'status'       => Shipment::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        return $shipment->fresh();
    }

    /** 取消 */
    public function cancel(int $tenantId, int $shipmentId): Shipment
    {
        $shipment = $this->getShipment($tenantId, $shipmentId);

        if (! $shipment->canCancel()) {
            throw new UnprocessableEntityHttpException("Shipment status '{$shipment->status}' does not allow cancellation");
        }

        $shipment->update(['status' => Shipment::STATUS_CANCELLED]);

        return $shipment->fresh();
    }

    public function getShipment(int $tenantId, int $shipmentId): Shipment
    {
        TenantContext::setTenantId((string) $tenantId);

        return Shipment::where('shipment_id', $shipmentId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    /** 订单关联发货单（订单详情接驳） */
    public function listByOrder(int $tenantId, string $orderNo): array
    {
        TenantContext::setTenantId((string) $tenantId);

        return Shipment::where('tenant_id', $tenantId)
            ->where('order_no', $orderNo)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function getList(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Shipment::where('tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['order_no'])) {
            $query->where('order_no', $filters['order_no']);
        }
        if (! empty($filters['tracking_no'])) {
            $query->where('tracking_no', 'like', '%' . $filters['tracking_no'] . '%');
        }

        $paginator = $query->orderByDesc('created_at')
            ->paginate(
                $filters['per_page'] ?? 20,
                ['*'],
                'page',
                max(1, (int) ($filters['page'] ?? 1))
            );

        return [
            'data'      => $paginator->items(),
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
