<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;

Route::prefix('tenant/payment')->group(function () {
    Route::get('/config', [TenantPaymentController::class, 'getPaymentConfig'])->middleware('rbac.permission:payment.view');
    Route::put('/{driver}', [TenantPaymentController::class, 'updatePaymentConfig'])->middleware('rbac.permission:payment.create');
    Route::get('/orders', [TenantPaymentController::class, 'index'])->middleware('rbac.permission:payment.view');
    Route::post('/orders', [TenantPaymentController::class, 'store'])->middleware('rbac.permission:payment.create');
    Route::get('/orders/{orderId}', [TenantPaymentController::class, 'show'])->middleware('rbac.permission:payment.view');
});
