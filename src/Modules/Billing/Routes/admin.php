<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Billing\Http\Controllers\SubscriptionController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantCreditController;

// 管理员后台 - 订阅计划管理
Route::prefix('admin/billing')->group(function () {
    Route::get('/plans', [SubscriptionController::class, 'plans']);
    Route::post('/plans', [SubscriptionController::class, 'storePlan']);
    Route::put('/plans/{planId}', [SubscriptionController::class, 'updatePlan']);
    Route::delete('/plans/{planId}', [SubscriptionController::class, 'destroyPlan']);
    Route::get('/plans/{planId}', [SubscriptionController::class, 'showPlan']);
});

// 管理员后台 - 积分管理
Route::prefix('admin/billing')->group(function () {
    Route::get('/credits/overview', [TenantCreditController::class, 'adminOverview']);
    Route::post('/credits/batch-recharge', [TenantCreditController::class, 'batchRecharge']);
});
