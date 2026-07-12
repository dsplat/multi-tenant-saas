<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;

Route::get('/tenants/{tenantId}/payment/config', [TenantPaymentController::class, 'getPaymentConfig']);
Route::put('/tenants/{tenantId}/payment/{driver}', [TenantPaymentController::class, 'updatePaymentConfig']);
Route::get('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'index']);
Route::post('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'store']);
Route::post('/tenants/{tenantId}/payment-orders/refund', [TenantPaymentController::class, 'refund']);
Route::get('/tenants/{tenantId}/payment-orders/refund-status', [TenantPaymentController::class, 'refundStatus']);
