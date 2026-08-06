<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Order\Services\OrderService;

/**
 * 统一订单中心
 *
 * Console（Operator）：订单列表/详情/退款
 * H5（User，VerifyOperatorTenant 放行）：下单/支付/我的订单
 */
class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
    ) {}

    // ========== Console ==========

    /**
     * 订单列表（支持按 order_type/status/user_id 过滤）
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $result = $this->orderService->getList($tenantId, $request->only([
            'order_type', 'status', 'user_id', 'page', 'per_page',
        ]));

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * 订单详情
     */
    public function show(string $orderNo): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $order = $this->orderService->getOrder($tenantId, $orderNo);

        return response()->json(['success' => true, 'data' => $order]);
    }

    /**
     * 退款
     */
    public function refund(Request $request, string $orderNo): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $order = $this->orderService->refundOrder($tenantId, $orderNo, $request->input('reason'));

        return response()->json(['success' => true, 'data' => $order]);
    }

    // ========== C 端下单与支付 ==========

    /**
     * 创建订单（终端用户）
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_type'              => 'nullable|string|in:registration,product,course,exchange',
            'pay_method'              => 'required|string|in:cash,points,mixed',
            'points_to_use'           => 'nullable|integer|min:0',
            'items'                   => 'required|array|min:1',
            'items.*.sku_id'          => 'nullable|integer',
            'items.*.item_type'       => 'nullable|string|max:30',
            'items.*.ref_id'          => 'nullable|integer',
            'items.*.item_name'       => 'nullable|string|max:255',
            'items.*.unit_price'      => 'nullable|numeric|min:0',
            'items.*.points_unit_price' => 'nullable|integer|min:0',
            'items.*.quantity'        => 'nullable|integer|min:1|max:99',
            'source'                  => 'nullable|array',
            'metadata'                => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $userId = $request->user()?->user_id;

        $order = $this->orderService->createOrder($tenantId, $userId ? (int) $userId : null, $validated);

        return response()->json(['success' => true, 'data' => $order], 201);
    }

    /**
     * 发起支付（虚拟支付即时完成；现金返回网关参数）
     */
    public function pay(Request $request, string $orderNo): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $result = $this->orderService->initiatePayment($tenantId, $orderNo, $request->input('openid'));

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * 支付回调（框架级，验签后调用）
     */
    public function payNotify(Request $request): JsonResponse
    {
        $orderNo = $request->input('out_trade_no') ?? $request->input('order_no');
        $transactionId = $request->input('transaction_id') ?? $request->input('trade_no', '');

        if (! $orderNo) {
            return response()->json(['success' => false, 'message' => 'Missing order_no'], 400);
        }

        Log::info('OrderController: pay notify received', [
            'order_no'       => $orderNo,
            'transaction_id' => $transactionId,
        ]);

        $result = $this->orderService->confirmPayment($orderNo, $transactionId);

        return response()->json([
            'success' => $result,
            'code'    => $result ? 'SUCCESS' : 'FAIL',
            'message' => $result ? 'OK' : 'Order not found',
        ]);
    }

    /**
     * 我的订单（终端用户）
     */
    public function myOrders(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $userId = $request->user()?->user_id;

        if (! $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $filters = $request->only(['order_type', 'status', 'per_page']);
        $filters['user_id'] = (int) $userId;

        return response()->json([
            'success' => true,
            'data'    => $this->orderService->getList($tenantId, $filters),
        ]);
    }
}
