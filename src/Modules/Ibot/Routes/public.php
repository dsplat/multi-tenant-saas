<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Ibot\Http\Controllers\IbotWebhookController;

/*
|--------------------------------------------------------------------------
| Ibot Public Routes
|--------------------------------------------------------------------------
|
| ibot webhook 入口（无需认证，控制器内强制验签——docs/ibot.md 铁律）。
| 完整路径：api/v1/ibot/webhook/wechat-work/{ibotId}
|
*/

Route::get('/ibot/webhook/wechat-work/{ibotId}', [IbotWebhookController::class, 'verifyWechatWork'])
    ->whereNumber('ibotId');

Route::post('/ibot/webhook/wechat-work/{ibotId}', [IbotWebhookController::class, 'handleWechatWork'])
    ->whereNumber('ibotId');
