<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrder;
use MultiTenantSaas\Modules\Commerce\Services\CommerceOrderService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * 商业体订单（console 端：租户下单/支付/查询）
 */
class CommerceOrderController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 下单
     */
    public function store(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.sku_id' => 'required|integer',
            'items.*.qty' => 'sometimes|integer|min:1|max:999',
        ]);

        try {
            $order = app(CommerceOrderService::class)->placeOrder(
                (int) $request->user()->getKey(),
                $validated['items']
            );

            app(AuditService::class)->log('create', 'commerce_order', $order->order_id, null, [
                'order_no' => $order->order_no,
                'amount' => $order->amount,
            ]);

            return response()->json(['success' => true, 'data' => $order->load('items')], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 发起支付（平台商户预下单）
     */
    public function pay(Request $request, int $orderId)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $order = CommerceOrder::find($orderId);
        if (! $order) {
            return response()->json(['success' => false, 'message' => '订单不存在'], 404);
        }

        $validated = $request->validate([
            'channel' => 'required|in:wechat_h5,alipay_web,alipay_wap',
        ]);

        try {
            $result = app(CommerceOrderService::class)->pay($order, $validated['channel']);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 本租户订单列表
     */
    public function index(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = CommerceOrder::query()->with('items');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * 订单详情
     */
    public function show(Request $request, int $orderId)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $order = CommerceOrder::with('items')->find($orderId);
        if (! $order) {
            return response()->json(['success' => false, 'message' => '订单不存在'], 404);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }

    /**
     * 取消订单（仅 pending）
     */
    public function cancel(Request $request, int $orderId)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $order = CommerceOrder::find($orderId);
        if (! $order) {
            return response()->json(['success' => false, 'message' => '订单不存在'], 404);
        }

        try {
            app(CommerceOrderService::class)->cancel($order);

            app(AuditService::class)->log('cancel', 'commerce_order', $order->order_id, null, [
                'order_no' => $order->order_no,
            ]);

            return response()->json(['success' => true, 'message' => '订单已取消']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
