<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Wechat\Http\Controllers\TenantWechatAuthController;

// 租户后台 - 微信第三方平台授权管理（与 Auth 模块 tenant/auth/oauth 同权限口径）
Route::prefix('tenant/wechat')->middleware('rbac.permission:setting.update')->group(function () {
    Route::get('/status', [TenantWechatAuthController::class, 'status']);
    Route::get('/capability', [TenantWechatAuthController::class, 'capability']);
    Route::post('/authorize', [TenantWechatAuthController::class, 'startAuthorization']);
    Route::post('/revoke', [TenantWechatAuthController::class, 'revoke']);
});
