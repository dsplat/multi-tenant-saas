<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Wechat\Http\Controllers\AdminComponentProviderController;

// 平台管理后台 - 微信第三方平台组件配置（组件凭证 CRUD / 连接测试 / 已授权租户列表）
Route::prefix('wechat')->group(function () {
    Route::middleware('rbac.permission:setting.view')->group(function () {
        Route::get('/providers', [AdminComponentProviderController::class, 'providerIndex']);
        Route::get('/authorizations', [AdminComponentProviderController::class, 'authorizations']);
    });

    Route::middleware('rbac.permission:setting.update')->group(function () {
        Route::post('/providers', [AdminComponentProviderController::class, 'providerStore']);
        Route::put('/providers/{providerId}', [AdminComponentProviderController::class, 'providerUpdate']);
        Route::delete('/providers/{providerId}', [AdminComponentProviderController::class, 'providerDestroy']);
        Route::post('/providers/{providerId}/test', [AdminComponentProviderController::class, 'providerTest']);
    });
});
