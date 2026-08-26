<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\WechatWork\Http\Controllers\AdminServiceProviderController;

// 平台管理后台 - 企微服务商配置（服务商凭证 CRUD / 连接测试 / 已授权租户列表）
Route::prefix('wechat-work')->group(function () {
    Route::middleware('rbac.permission:setting.view')->group(function () {
        Route::get('/providers', [AdminServiceProviderController::class, 'providerIndex']);
        Route::get('/authorizations', [AdminServiceProviderController::class, 'authorizations']);
    });

    Route::middleware('rbac.permission:setting.update')->group(function () {
        Route::post('/providers', [AdminServiceProviderController::class, 'providerStore']);
        Route::put('/providers/{providerId}', [AdminServiceProviderController::class, 'providerUpdate']);
        Route::delete('/providers/{providerId}', [AdminServiceProviderController::class, 'providerDestroy']);
        Route::post('/providers/{providerId}/test', [AdminServiceProviderController::class, 'providerTest']);
    });
});
