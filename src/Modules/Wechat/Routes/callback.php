<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Wechat\Http\Controllers\ComponentCallbackController;

// 微信第三方平台组件回调（裸路由，无中间件链——回调请求 Host 为平台统一回调域，
// 无租户上下文，控制器内按组件凭证验签/解密；参照 WechatWork suite.php 先例）
Route::prefix('api/v1/wechat')->group(function () {
    // 组件回调 URL 有效性验证（GET echostr）
    Route::get('/component/callback', [ComponentCallbackController::class, 'verify']);

    // 组件事件推送（POST 加密 XML：component_verify_ticket / authorized /
    // updateauthorized / unauthorized）
    Route::post('/component/callback', [ComponentCallbackController::class, 'handle']);

    // 授权发起统一入口（平台域 auth.neihang.com，浏览器直接访问后 302 到微信
    // 授权页——微信「授权发起页域名」校验跳转来源，必须收敛到平台域）
    Route::get('/component/launch', [ComponentCallbackController::class, 'launch']);

    // 授权页完成后的平台域回跳（浏览器重定向，带 auth_code + state，
    // state 携带租户前缀并经缓存校验防伪造）
    Route::get('/component/authorize-callback', [ComponentCallbackController::class, 'authorizeCallback']);
});
