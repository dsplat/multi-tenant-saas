<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Domain\Http\Controllers\SlugController;
use MultiTenantSaas\Modules\Domain\Http\Controllers\TenantDomainController;

Route::prefix('tenant/{tenantId}/domain')->group(function () {
    Route::get('/', [TenantDomainController::class, 'index']);
    Route::post('/', [TenantDomainController::class, 'store']);
    Route::put('/', [TenantDomainController::class, 'update']);
    Route::delete('/', [TenantDomainController::class, 'destroy']);

    // 域名归属文件验证
    Route::post('/verify-token', [TenantDomainController::class, 'generateVerifyToken']);
    Route::post('/verify', [TenantDomainController::class, 'verify']);
    Route::get('/verify-info', [TenantDomainController::class, 'verifyInfo']);

    // 第三方平台（微信/企微/支付宝）验证文件管理
    Route::post('/verify-files', [TenantDomainController::class, 'saveVerifyFiles']);
});

// Slug 设置（Console 端）
Route::prefix('tenant/slug')->group(function () {
    Route::put('/', [SlugController::class, 'update']);
    Route::get('/check', [SlugController::class, 'check']);
});
