<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Wechat\Http\Controllers\TenantWechatAuthController;
use MultiTenantSaas\Modules\Wechat\Http\Controllers\TenantWechatMessageController;

// 租户后台 - 微信第三方平台授权管理（与 Auth 模块 tenant/auth/oauth 同权限口径）
Route::prefix('tenant/wechat')->middleware('rbac.permission:setting.update')->group(function () {
    Route::get('/status', [TenantWechatAuthController::class, 'status']);
    Route::get('/capability', [TenantWechatAuthController::class, 'capability']);
    Route::post('/authorize', [TenantWechatAuthController::class, 'startAuthorization']);
    Route::post('/revoke', [TenantWechatAuthController::class, 'revoke']);
});

// 租户后台 - 服务号消息能力（模板消息 / 客服消息，同权限口径）
Route::prefix('tenant/wechat/messages')->middleware('rbac.permission:setting.update')->group(function () {
    Route::get('/status', [TenantWechatMessageController::class, 'status']);
    Route::get('/templates', [TenantWechatMessageController::class, 'templates']);
    Route::post('/templates', [TenantWechatMessageController::class, 'storeTemplate']);
    Route::delete('/templates/{id}', [TenantWechatMessageController::class, 'destroyTemplate']);
    Route::post('/templates/test', [TenantWechatMessageController::class, 'sendTemplate']);
    Route::post('/custom/send', [TenantWechatMessageController::class, 'sendCustom']);
    Route::get('/logs', [TenantWechatMessageController::class, 'logs']);
});
