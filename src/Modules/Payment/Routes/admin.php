<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;

Route::prefix('admin/payments')->group(function () {
    Route::get('/config', [TenantPaymentController::class, 'getPaymentConfig']);
    Route::put('/config/{driver}', [TenantPaymentController::class, 'updatePaymentConfig']);
    Route::get('/orders', [TenantPaymentController::class, 'adminIndex']);
    Route::get('/orders/{orderId}', [TenantPaymentController::class, 'adminShow']);
});
