<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Logistics\Services\ShipmentService;

/**
 * 发货单管理（Console）
 */
class ShipmentController extends Controller
{
    public function __construct(
        protected ShipmentService $shipmentService,
    ) {}

    /** 发货单列表（支持按 status/order_no/tracking_no 过滤） */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $result = $this->shipmentService->getList($tenantId, $request->only([
            'status', 'order_no', 'tracking_no', 'page', 'per_page',
        ]));

        return response()->json(['success' => true, 'data' => $result]);
    }

    /** 登记发货单 */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_no'         => 'required|string|max:64',
            'carrier'          => 'nullable|string|max:60',
            'tracking_no'      => 'nullable|string|max:64',
            'receiver_name'    => 'nullable|string|max:60',
            'receiver_phone'   => 'nullable|string|max:30',
            'receiver_address' => 'nullable|string|max:500',
            'items'            => 'nullable|array',
            'remark'           => 'nullable|string|max:500',
        ]);

        $tenantId = (int) TenantContext::getId();

        $shipment = $this->shipmentService->createShipment($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $shipment], 201);
    }

    /** 发货单详情 */
    public function show(int $shipmentId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->shipmentService->getShipment($tenantId, $shipmentId),
        ]);
    }

    /** 发货（填运单号） */
    public function ship(Request $request, int $shipmentId): JsonResponse
    {
        $validated = $request->validate([
            'carrier'     => 'nullable|string|max:60',
            'tracking_no' => 'required|string|max:64',
        ]);

        $tenantId = (int) TenantContext::getId();

        $shipment = $this->shipmentService->ship($tenantId, $shipmentId, $validated['carrier'] ?? null, $validated['tracking_no']);

        return response()->json(['success' => true, 'data' => $shipment]);
    }

    /** 签收 */
    public function deliver(int $shipmentId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->shipmentService->deliver($tenantId, $shipmentId),
        ]);
    }

    /** 取消 */
    public function cancel(int $shipmentId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->shipmentService->cancel($tenantId, $shipmentId),
        ]);
    }

    /** 订单关联发货单（订单详情接驳） */
    public function byOrder(string $orderNo): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->shipmentService->listByOrder($tenantId, $orderNo),
        ]);
    }
}
