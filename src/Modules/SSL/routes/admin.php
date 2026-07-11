<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\SSL\Http\Controllers\TenantSslController;

Route::prefix('admin/ssl')->group(function () {
    Route::get('/', [TenantSslController::class, 'index']);
    Route::post('/{tenantId}', [TenantSslController::class, 'store']);
    Route::delete('/{tenantId}', [TenantSslController::class, 'destroy']);
    Route::post('/{tenantId}/renew', [TenantSslController::class, 'renew']);
});
