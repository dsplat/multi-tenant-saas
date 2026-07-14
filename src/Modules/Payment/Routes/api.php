<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;

Route::middleware('rbac.permission:payment.view')->group(function () {
    Route::get('/tenants/{tenantId}/payment/config', [TenantPaymentController::class, 'getPaymentConfig']);
    Route::get('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'index']);
    Route::get('/tenants/{tenantId}/payment-orders/refund-status', [TenantPaymentController::class, 'refundStatus']);
});

Route::middleware('rbac.permission:payment.create')->group(function () {
    Route::put('/tenants/{tenantId}/payment/{driver}', [TenantPaymentController::class, 'updatePaymentConfig']);
    Route::post('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'store']);
    Route::post('/tenants/{tenantId}/payment-orders/refund', [TenantPaymentController::class, 'refund']);
});
