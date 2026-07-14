<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Billing\Http\Controllers\SubscriptionController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantCreditController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantQuotaController;

// 租户后台 - 订阅管理
Route::prefix('billing')->group(function () {
    Route::get('/subscription', [SubscriptionController::class, 'current'])->middleware('rbac.permission:credit.view');
    Route::post('/subscription/subscribe', [SubscriptionController::class, 'subscribe'])->middleware('rbac.permission:subscription.manage');
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware('rbac.permission:subscription.manage');
    Route::post('/subscription/change', [SubscriptionController::class, 'changePlan'])->middleware('rbac.permission:subscription.manage');
    Route::get('/subscription/history', [SubscriptionController::class, 'history'])->middleware('rbac.permission:credit.view');
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans'])->middleware('rbac.permission:credit.view');
    Route::get('/subscription/plans/{planId}', [SubscriptionController::class, 'showPlan'])->middleware('rbac.permission:credit.view');
});

// 租户后台 - 积分管理
Route::prefix('billing')->group(function () {
    Route::get('/credits', [TenantCreditController::class, 'index'])->middleware('rbac.permission:credit.view');
    Route::post('/credits/recharge', [TenantCreditController::class, 'recharge'])->middleware('rbac.permission:subscription.manage');
    Route::get('/credits/history', [TenantCreditController::class, 'history'])->middleware('rbac.permission:credit.view');
});

// 租户后台 - 配额管理
Route::prefix('billing')->group(function () {
    Route::get('/quotas', [TenantQuotaController::class, 'index'])->middleware('rbac.permission:credit.view');
    Route::get('/quotas/usage', [TenantQuotaController::class, 'usage'])->middleware('rbac.permission:credit.view');
});
