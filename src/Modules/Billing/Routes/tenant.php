<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Billing\Http\Controllers\SubscriptionController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantCreditController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantQuotaController;

// 租户后台 - 订阅管理
Route::prefix('billing')->group(function () {
    Route::get('/subscription', [SubscriptionController::class, 'current']);
    Route::post('/subscription/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('/subscription/change', [SubscriptionController::class, 'changePlan']);
    Route::get('/subscription/history', [SubscriptionController::class, 'history']);
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
    Route::get('/subscription/plans/{planId}', [SubscriptionController::class, 'showPlan']);
});

// 租户后台 - 积分管理
Route::prefix('billing')->group(function () {
    Route::get('/credits', [TenantCreditController::class, 'index']);
    Route::post('/credits/recharge', [TenantCreditController::class, 'recharge']);
    Route::get('/credits/history', [TenantCreditController::class, 'history']);
});

// 租户后台 - 配额管理
Route::prefix('billing')->group(function () {
    Route::get('/quotas', [TenantQuotaController::class, 'index']);
    Route::get('/quotas/usage', [TenantQuotaController::class, 'usage']);
});
