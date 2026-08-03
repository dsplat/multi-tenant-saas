<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Commerce\Http\Controllers\CommercePayCallbackController;

// 平台级支付回调（无需认证，PayService 内以平台商户配置验签）
Route::post('/commerce/pay/wechat/notify', [CommercePayCallbackController::class, 'wechatNotify']);
Route::post('/commerce/pay/alipay/notify', [CommercePayCallbackController::class, 'alipayNotify']);
// 支付宝同步回跳为浏览器 GET（参数同样带签），保留 GET 入口
Route::get('/commerce/pay/alipay/notify', [CommercePayCallbackController::class, 'alipayNotify']);
