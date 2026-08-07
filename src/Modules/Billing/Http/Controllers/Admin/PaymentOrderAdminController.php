<?php

namespace MultiTenantSaas\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Billing\Models\PaymentOrder;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 平台管理：支付订单运营（B2）
 *
 * 订单列表/详情/手动补单（mark_paid）/关单。
 * PaymentOrder 挂在 Billing 模块；注册于 Billing 路由而非 Payment 模块
 * （payment 模块 default_enabled=false，其路由默认不加载）。
 */
class PaymentOrderAdminController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 跨租户订单列表
     */
    public function index(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $perPage = min((int) $request->input('per_page', 15), 100);
        // admin 上下文无 TenantContext，绕过租户作用域直查
        $query = PaymentOrder::query()->withoutGlobalScope(TenantScope::class);

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('driver')) {
            $query->where('driver', $request->driver);
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
        $this->ensureSuperAdmin($request);

        $order = PaymentOrder::withoutGlobalScope(TenantScope::class)->findOrFail($orderId);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * 手动补单（pending → paid）
     *
     * 用于线下收款/回调丢失场景；仅记账改状态，
     * 履约联动由项目层按 extra 中的业务引用自行接线。
     */
    public function markPaid(Request $request, int $orderId)
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'transaction_id' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:500',
        ]);

        $order = PaymentOrder::withoutGlobalScope(TenantScope::class)->findOrFail($orderId);

        if ($order->status !== 'pending') {
            return response()->json(['success' => false, 'message' => trans('payment.order_status_invalid')], 422);
        }

        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
            'transaction_id' => $validated['transaction_id'] ?? ('MANUAL-' . now()->format('YmdHis')),
            'extra' => array_merge($order->extra ?? [], [
                'manual_paid' => true,
                'manual_note' => $validated['note'] ?? '',
            ]),
        ]);

        app(AuditService::class)->log('admin_mark_paid', 'payment_order', $order->id, null, [
            'order_no' => $order->order_no,
            'amount' => $order->amount,
            'transaction_id' => $order->transaction_id,
        ]);

        return response()->json(['success' => true, 'message' => trans('payment.mark_paid_success'), 'data' => $order]);
    }

    /**
     * 关单（pending → cancelled）
     */
    public function close(Request $request, int $orderId)
    {
        $this->ensureSuperAdmin($request);

        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $order = PaymentOrder::withoutGlobalScope(TenantScope::class)->findOrFail($orderId);

        if ($order->status !== 'pending') {
            return response()->json(['success' => false, 'message' => trans('payment.order_status_invalid')], 422);
        }

        $order->update([
            'status' => 'cancelled',
            'extra' => array_merge($order->extra ?? [], [
                'manual_closed' => true,
                'close_note' => $request->input('note', ''),
            ]),
        ]);

        app(AuditService::class)->log('admin_close_order', 'payment_order', $order->id, null, [
            'order_no' => $order->order_no,
            'amount' => $order->amount,
        ]);

        return response()->json(['success' => true, 'message' => trans('payment.close_success'), 'data' => $order]);
    }
}
