<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Domain\Http\Controllers\TenantDomainController;

Route::prefix('tenant/domain')->group(function () {
    Route::get('/', [TenantDomainController::class, 'index']);
    Route::post('/', [TenantDomainController::class, 'store']);
    Route::put('/', [TenantDomainController::class, 'update']);
    Route::delete('/', [TenantDomainController::class, 'destroy']);

    // 域名归属文件验证
    Route::post('/verify-token', [TenantDomainController::class, 'generateVerifyToken']);
    Route::post('/verify', [TenantDomainController::class, 'verify']);
    Route::get('/verify-info', [TenantDomainController::class, 'verifyInfo']);
});
