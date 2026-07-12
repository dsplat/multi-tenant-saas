<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;

Route::prefix('tenant/payment')->group(function () {
    Route::get('/config', [TenantPaymentController::class, 'getPaymentConfig']);
    Route::put('/{driver}', [TenantPaymentController::class, 'updatePaymentConfig']);
    Route::get('/orders', [TenantPaymentController::class, 'index']);
    Route::post('/orders', [TenantPaymentController::class, 'store']);
    Route::get('/orders/{orderId}', [TenantPaymentController::class, 'show']);
});
