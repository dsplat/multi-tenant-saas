<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;

Route::prefix('payments')->group(function () {
    Route::get('/config', [TenantPaymentController::class, 'getPaymentConfig'])->middleware('rbac.permission:payment.view');
    Route::put('/config/{driver}', [TenantPaymentController::class, 'updatePaymentConfig'])->middleware('rbac.permission:payment.create');
    Route::get('/orders', [TenantPaymentController::class, 'adminIndex'])->middleware('rbac.permission:payment.view');
    Route::get('/orders/{orderId}', [TenantPaymentController::class, 'adminShow'])->middleware('rbac.permission:payment.view');
});
