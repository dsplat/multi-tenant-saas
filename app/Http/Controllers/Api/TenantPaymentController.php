<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\PayService;

class TenantPaymentController extends Controller
{
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
        return response()->json(['success' => true, 'message' => '支付配置已更新']);
    }

    public function wechatNotify(Request $request)
    {
        try {
            $result = PayService::handleCallback('wechat', $request);
            \Log::info('微信支付回调成功', $result);
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
            return response('success');
        } catch (\Throwable $e) {
            \Log::error('支付宝回调失败', [
                'error' => $e->getMessage(),
                'query' => $request->query(),
            ]);
            return response('fail', 400);
        }
    }

    private function ensureTenantAccess(Request $request, int $tenantId): void
    {
        $user = $request->user();

        if ($user->role === 'super_admin') {
            abort(403, '系统管理员不能访问租户数据');
        }

        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (!$tenantUser) {
            abort(403, '您不属于该租户');
        }
    }
}
