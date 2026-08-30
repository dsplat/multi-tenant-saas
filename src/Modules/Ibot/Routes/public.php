<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Ibot\Http\Controllers\IbotWebhookController;
use MultiTenantSaas\Modules\Ibot\Http\Controllers\WechatWorkBindCallbackController;

/*
|--------------------------------------------------------------------------
| Ibot Public Routes
|--------------------------------------------------------------------------
|
| ibot webhook 入口（无需认证，控制器内强制验签——docs/ibot.md 铁律）。
| 完整路径：api/v1/ibot/webhook/wechat-work/{ibotId}
|
| 企微扫码即绑回调（网页授权 OAuth 落地，同样无需认证——
| code 一次性 + 绑定码一次性消费保证安全）。
|
*/

Route::get('/ibot/webhook/wechat-work/{ibotId}', [IbotWebhookController::class, 'verifyWechatWork'])
    ->whereNumber('ibotId');

Route::post('/ibot/webhook/wechat-work/{ibotId}', [IbotWebhookController::class, 'handleWechatWork'])
    ->whereNumber('ibotId');

Route::get('/ibot/bind/wechat-work/callback', [WechatWorkBindCallbackController::class, 'handle']);

Route::post('/ibot/bind/wechat-work/confirm', [WechatWorkBindCallbackController::class, 'confirm']);
