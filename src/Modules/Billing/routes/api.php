<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Billing\Http\Controllers\SubscriptionController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantCreditController;
use MultiTenantSaas\Modules\Billing\Http\Controllers\TenantQuotaController;

// 积分管理
Route::get('/tenants/{tenantId}/credits', [TenantCreditController::class, 'index']);

// 配额
Route::get('/tenants/{tenantId}/quotas', [TenantQuotaController::class, 'index']);

// 订阅管理
Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
Route::get('/subscription/plans/{planId}', [SubscriptionController::class, 'showPlan']);
Route::post('/subscription/plans', [SubscriptionController::class, 'storePlan']);
Route::put('/subscription/plans/{planId}', [SubscriptionController::class, 'updatePlan']);
Route::delete('/subscription/plans/{planId}', [SubscriptionController::class, 'destroyPlan']);
Route::get('/tenants/{tenantId}/subscription', [SubscriptionController::class, 'current']);
Route::post('/tenants/{tenantId}/subscription/subscribe', [SubscriptionController::class, 'subscribe']);
Route::post('/tenants/{tenantId}/subscription/cancel', [SubscriptionController::class, 'cancel']);
Route::post('/tenants/{tenantId}/subscription/change', [SubscriptionController::class, 'changePlan']);
Route::get('/tenants/{tenantId}/subscription/history', [SubscriptionController::class, 'history']);
