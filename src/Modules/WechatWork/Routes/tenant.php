<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\WechatWork\Http\Controllers\TenantWechatWorkAuthController;

// 租户后台 - 企微代开发授权管理（与 Auth 模块 tenant/auth/oauth 同权限口径）
Route::prefix('tenant/wechat-work')->middleware('rbac.permission:setting.update')->group(function () {
    Route::get('/status', [TenantWechatWorkAuthController::class, 'status']);
    Route::post('/authorize', [TenantWechatWorkAuthController::class, 'startAuthorization']);
    Route::post('/revoke', [TenantWechatWorkAuthController::class, 'revoke']);
});
