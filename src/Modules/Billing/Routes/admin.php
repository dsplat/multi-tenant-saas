<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Billing\Http\Controllers\Admin\PaymentOrderAdminController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\Admin\SubscriptionAdminController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\SubscriptionController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantCreditController;

// 管理员后台 - 订阅计划管理
Route::prefix('billing')->group(function () {
    Route::get('/plans', [SubscriptionController::class, 'plans'])->middleware('rbac.permission:subscription.manage');
    Route::post('/plans', [SubscriptionController::class, 'storePlan'])->middleware('rbac.permission:subscription.manage');
    Route::put('/plans/{planId}', [SubscriptionController::class, 'updatePlan'])->middleware('rbac.permission:subscription.manage');
    Route::delete('/plans/{planId}', [SubscriptionController::class, 'destroyPlan'])->middleware('rbac.permission:subscription.manage');
    Route::get('/plans/{planId}', [SubscriptionController::class, 'showPlan'])->middleware('rbac.permission:subscription.manage');
});

// 管理员后台 - 积分管理
Route::prefix('billing')->group(function () {
    Route::get('/credits/overview', [TenantCreditController::class, 'adminOverview'])->middleware('rbac.permission:credit.view');
    Route::post('/credits/batch-recharge', [TenantCreditController::class, 'batchRecharge'])->middleware('rbac.permission:credit.recharge');
});

// 管理员后台 - 跨租户订阅总览与干预（B1）
Route::prefix('billing')->group(function () {
    Route::get('/subscriptions', [SubscriptionAdminController::class, 'index'])->middleware('rbac.permission:subscription.manage');
    Route::get('/subscriptions/{tenantId}/history', [SubscriptionAdminController::class, 'history'])->middleware('rbac.permission:subscription.manage');
    Route::post('/subscriptions/{tenantId}/cancel', [SubscriptionAdminController::class, 'cancel'])->middleware('rbac.permission:subscription.manage');
    Route::post('/subscriptions/{tenantId}/resume', [SubscriptionAdminController::class, 'resume'])->middleware('rbac.permission:subscription.manage');
    Route::post('/subscriptions/{tenantId}/change-plan', [SubscriptionAdminController::class, 'changePlan'])->middleware('rbac.permission:subscription.manage');
});

// 管理员后台 - 支付订单运营（B2；PaymentOrder 在 Billing 模块，
// URL 保持 /payments/orders 与前端页面一致）
Route::prefix('payments')->group(function () {
    Route::get('/orders', [PaymentOrderAdminController::class, 'index'])->middleware('rbac.permission:payment.view');
    Route::get('/orders/{orderId}', [PaymentOrderAdminController::class, 'show'])->middleware('rbac.permission:payment.view');
    Route::post('/orders/{orderId}/mark-paid', [PaymentOrderAdminController::class, 'markPaid'])->middleware('rbac.permission:payment.create');
    Route::post('/orders/{orderId}/close', [PaymentOrderAdminController::class, 'close'])->middleware('rbac.permission:payment.create');
});
