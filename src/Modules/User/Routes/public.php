<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantOnboardingController;

/**
 * 租户引导式注册路由（Operator 直连租户模式）
 *
 * 中间件：operator.auth（要求已认证的 Operator 身份）
 *
 * 注意：尽管放在 public.php（命名上无前缀），但所有路由都受 operator.auth 保护，
 * 未携带有效 Operator Bearer token 的请求将返回 401
 *
 * 路由前缀由 ModuleServiceProvider 加载时自动加上 'api/v1'，最终路径：
 *   POST /api/v1/tenants/onboarding/start
 *   POST /api/v1/tenants/onboarding/{step}     (step: 2,3,4)
 *   GET  /api/v1/tenants/onboarding/status
 *   POST /api/v1/tenants/onboarding/complete
 */
Route::middleware('operator.auth')->group(function () {
    Route::post('/tenants/onboarding/start', [TenantOnboardingController::class, 'register']);
    Route::post('/tenants/onboarding/status', [TenantOnboardingController::class, 'status']);
    Route::post('/tenants/onboarding/complete', [TenantOnboardingController::class, 'complete']);
    Route::post('/tenants/onboarding/{step}', [TenantOnboardingController::class, 'saveStep'])
        ->where('step', '[2-4]');
});
