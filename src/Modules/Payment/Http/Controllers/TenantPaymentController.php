<?php

namespace MultiTenantSaas\Modules\Payment\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Billing\Models\PaymentOrder;
use MultiTenantSaas\Modules\Billing\Services\PayService;
use MultiTenantSaas\Modules\Billing\Services\RefundService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class TenantPaymentController extends Controller
{
    use AuthorizesTenantAccess;

    public function getPaymentConfig(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        return response()->json(['success' => true, 'data' => PayService::getPaymentConfig($tenantId)]);
    }

    public function updatePaymentConfig(Request $request, int $tenantId, string $driver)
    {
        $this->ensureTenantAccess($request, $tenantId);

        if (! in_array($driver, ['wechat', 'alipay'])) {
            return response()->json(['success' => false, 'message' => trans('payment.unsupported_driver')], 400);
        }

        $allowed = $driver === 'wechat'
            ? ['app_id', 'mch_id', 'serial_no', 'private_key', 'notify_url']
            : ['app_id', 'ali_public_key', 'private_key', 'notify_url', 'mode'];

        PayService::updatePaymentConfig($tenantId, $driver, $request->only($allowed));

        AuditService::log('update', 'payment_config', $tenantId, null, [
            'driver' => $driver,
            'fields' => $allowed,
        ]);

        return response()->json(['success' => true, 'message' => trans('payment.config_updated')]);
    }

    public function wechatNotify(Request $request)
    {
        try {
            $result = PayService::handleCallback('wechat', $request);
            \Log::info('WeChat payment callback success', $result);

            if (isset($result['order_id'])) {
                AuditService::log('payment_callback', 'payment_order', $result['order_id'], null, $result);
            }

            return response('success');
        } catch (\Throwable $e) {
            \Log::error('WeChat payment callback failed', [
                'error' => $e->getMessage(),
                'query' => $request->query(),
            ]);

            return response('fail', 400);
        }
    }

    public function alipayNotify(Request $request)
    {
        try {
            $result = PayService::handleCallback('alipay', $request);
            \Log::info('Alipay callback success', $result);

            if (isset($result['order_id'])) {
                AuditService::log('payment_callback', 'payment_order', $result['order_id'], null, $result);
            }

            return response('success');
        } catch (\Throwable $e) {
            \Log::error('Alipay callback failed', [
                'error' => $e->getMessage(),
                'query' => $request->query(),
            ]);

            return response('fail', 400);
        }
    }

    /**
     * 支付订单列表
     */
    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $orders = PaymentOrder::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

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
     * 创建支付订单
     */
    public function store(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'driver' => 'required|in:wechat,alipay',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $order = PaymentOrder::create([
            'tenant_id' => $tenantId,
            'order_no' => 'PAY' . date('YmdHis') . rand(1000, 9999),
            'driver' => $request->driver,
            'amount' => $request->amount,
            'description' => $request->description,
            'status' => 'pending',
        ]);

        AuditService::log('create', 'payment_order', $order->id, null, [
            'order_no' => $order->order_no,
            'driver' => $order->driver,
            'amount' => $order->amount,
        ]);

        return response()->json([
            'success' => true,
            'data' => $order,
        ], 201);
    }

    /**
     * 发起退款
     */
    public function refund(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'order_no' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = RefundService::refund(
                $tenantId,
                $validated['order_no'],
                $validated['amount'],
                $validated['reason'] ?? ''
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 查询退款状态
     */
    public function refundStatus(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'order_no' => 'required|string',
        ]);

        try {
            $result = RefundService::queryRefundStatus($tenantId, $request->input('order_no'));

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * 退款回调（微信）
     */
    public function wechatRefundNotify(Request $request)
    {
        try {
            $result = RefundService::handleRefundCallback('wechat', $request);
            \Log::info('WeChat refund callback success', $result);

            return response('success');
        } catch (\Throwable $e) {
            \Log::error('WeChat refund callback failed', ['error' => $e->getMessage()]);

            return response('fail', 400);
        }
    }

    /**
     * 退款回调（支付宝）
     */
    public function alipayRefundNotify(Request $request)
    {
        try {
            $result = RefundService::handleRefundCallback('alipay', $request);
            \Log::info('Alipay refund callback success', $result);

            return response('success');
        } catch (\Throwable $e) {
            \Log::error('Alipay refund callback failed', ['error' => $e->getMessage()]);

            return response('fail', 400);
        }
    }
}
