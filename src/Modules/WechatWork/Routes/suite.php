<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\WechatWork\Http\Controllers\SuiteCallbackController;
use MultiTenantSaas\Modules\WechatWork\Http\Controllers\TenantWechatWorkAuthController;

// 企微服务商套件回调（裸路由，无中间件链——回调请求 Host 为平台统一回调域，
// 无租户上下文，控制器内按服务商凭证验签/解密；参照 Domain verify-file.php 先例）
Route::prefix('api/v1/wechat-work')->group(function () {
    // 模板回调 URL 有效性验证（GET echostr）
    Route::get('/suite/callback', [SuiteCallbackController::class, 'verify']);

    // 模板回调事件推送（POST 加密 XML：suite_ticket / create_auth / cancel_auth）
    Route::post('/suite/callback', [SuiteCallbackController::class, 'handle']);

    // 租户扫码授权完成回跳（3rdapp/install redirect_uri，浏览器跳转，无认证，
    // state 携带租户前缀并经缓存校验防伪造）
    Route::get('/callback', [TenantWechatWorkAuthController::class, 'callback']);
});
