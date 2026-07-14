<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\SSL\Http\Controllers\TenantSslController;

Route::prefix('admin/ssl')->group(function () {
    Route::get('/', [TenantSslController::class, 'index'])->middleware('rbac.permission:ssl.manage');
    Route::post('/{tenantId}', [TenantSslController::class, 'store'])->middleware('rbac.permission:ssl.manage');
    Route::delete('/{tenantId}', [TenantSslController::class, 'destroy'])->middleware('rbac.permission:ssl.manage');
    Route::post('/{tenantId}/renew', [TenantSslController::class, 'renew'])->middleware('rbac.permission:ssl.manage');
});
