<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\SSL\Http\Controllers\TenantSslController;

Route::prefix('tenant/ssl')->group(function () {
    Route::get('/', [TenantSslController::class, 'index']);
    Route::post('/', [TenantSslController::class, 'store']);
    Route::delete('/', [TenantSslController::class, 'destroy']);
});
