<?php

use Illuminate\Support\Facades\Route;
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
