<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantOnboardingController;

/**
 * 租户引导式注册路由
 *
 * 路由前缀由 ModuleServiceProvider 加载时自动加上 'api/v1'，最终路径：
 *   POST /api/v1/tenants/onboarding/start      （公开，无需认证）
 *   POST /api/v1/tenants/onboarding/{step}      （需认证，step: 2,3,4）
 *   POST /api/v1/tenants/onboarding/status      （需认证）
 *   POST /api/v1/tenants/onboarding/complete    （需认证）
 */

// 注册启动（公开，无需认证）
Route::post('/tenants/onboarding/start', [TenantOnboardingController::class, 'register']);

// 后续步骤需要认证
Route::middleware('operator.auth')->group(function () {
    Route::post('/tenants/onboarding/status', [TenantOnboardingController::class, 'status']);
    Route::post('/tenants/onboarding/complete', [TenantOnboardingController::class, 'complete']);
    Route::post('/tenants/onboarding/{step}', [TenantOnboardingController::class, 'saveStep'])
        ->where('step', '[2-4]');
});
