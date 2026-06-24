<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\PaymentOrder;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Services\PayService;

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

        if (!in_array($driver, ['wechat', 'alipay'])) {
            return response()->json(['success' => false, 'message' => '不支持的支付方式'], 400);
        }

        $allowed = $driver === 'wechat'
            ? ['app_id', 'mch_id', 'serial_no', 'private_key', 'notify_url']
            : ['app_id', 'ali_public_key', 'private_key', 'notify_url', 'mode'];

        PayService::updatePaymentConfig($tenantId, $driver, $request->only($allowed));

        AuditService::log('update', 'payment_config', $tenantId, null, [
            'driver' => $driver,
            'fields' => $allowed,
        ]);

        return response()->json(['success' => true, 'message' => '支付配置已更新']);
    }

    public function wechatNotify(Request $request)
    {
        try {
            $result = PayService::handleCallback('wechat', $request);
            \Log::info('微信支付回调成功', $result);

            if (isset($result['order_id'])) {
                AuditService::log('payment_callback', 'payment_order', $result['order_id'], null, $result);
            }

            return response('success');
        } catch (\Throwable $e) {
            \Log::error('微信支付回调失败', [
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
            \Log::info('支付宝回调成功', $result);

            if (isset($result['order_id'])) {
                AuditService::log('payment_callback', 'payment_order', $result['order_id'], null, $result);
            }

            return response('success');
        } catch (\Throwable $e) {
            \Log::error('支付宝回调失败', [
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

}
