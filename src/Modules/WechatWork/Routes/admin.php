<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\WechatWork\Http\Controllers\AdminServiceProviderController;

// 平台管理后台 - 企微服务商配置（服务商凭证 CRUD / 连接测试 / 已授权租户列表）
Route::prefix('wechat-work')->group(function () {
    Route::middleware('rbac.permission:setting.view')->group(function () {
        Route::get('/providers', [AdminServiceProviderController::class, 'providerIndex']);
        Route::get('/authorizations', [AdminServiceProviderController::class, 'authorizations']);
        // 租户企微出口代理配置（9.1：企业侧接口可信 IP 出网）
        Route::get('/proxy/{tenantId}', [AdminServiceProviderController::class, 'proxyShow']);
        // 租户企微接入能力总览（阶段 C，11.4：能力包/许可台账/接入模式）
        Route::get('/capabilities/{tenantId}', [AdminServiceProviderController::class, 'capabilityShow']);
    });

    Route::middleware('rbac.permission:setting.update')->group(function () {
        Route::post('/providers', [AdminServiceProviderController::class, 'providerStore']);
        Route::put('/providers/{providerId}', [AdminServiceProviderController::class, 'providerUpdate']);
        Route::delete('/providers/{providerId}', [AdminServiceProviderController::class, 'providerDestroy']);
        Route::post('/providers/{providerId}/test', [AdminServiceProviderController::class, 'providerTest']);
        // 租户应用回调凭证回填（「开始代开发应用」的 Token/AESKey）
        Route::put('/authorizations/{authorizationId}/app-callback', [AdminServiceProviderController::class, 'appCallbackUpdate']);
        Route::put('/proxy/{tenantId}', [AdminServiceProviderController::class, 'proxyUpdate']);
    });
});
