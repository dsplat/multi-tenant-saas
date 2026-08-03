<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Commerce\Services\CommerceFulfillmentService;

/**
 * 平台级支付回调（租户向平台购买的订单）
 *
 * 无认证入口，PayService::handlePlatformCallback 内以平台商户配置验签。
 */
class CommercePayCallbackController extends Controller
{
    public function wechatNotify(Request $request)
    {
        try {
            app(CommerceFulfillmentService::class)->handlePlatformCallback('wechat', $request);

            return response('success');
        } catch (\Throwable $e) {
            Log::error('[Commerce] 平台微信回调失败', [
                'error' => $e->getMessage(),
                'query' => $request->query(),
            ]);

            return response('fail', 400);
        }
    }

    public function alipayNotify(Request $request)
    {
        try {
            app(CommerceFulfillmentService::class)->handlePlatformCallback('alipay', $request);

            return response('success');
        } catch (\Throwable $e) {
            Log::error('[Commerce] 平台支付宝回调失败', [
                'error' => $e->getMessage(),
                'query' => $request->query(),
            ]);

            return response('fail', 400);
        }
    }
}
