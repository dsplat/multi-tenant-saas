<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\AuthController;

// 公开路由（无需认证）
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:3,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:3,1');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])
        ->middleware('throttle:5,1');
    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:3,1');

    // SSO
    Route::get('/sso/{provider}/redirect', [AuthController::class, 'ssoRedirect']);
    Route::get('/sso/{provider}/callback', [AuthController::class, 'ssoCallback']);
    Route::post('/sso/{provider}/callback', [AuthController::class, 'ssoCallback']);
});

// 管理员登录（公开，无需认证）
Route::post('/admin/auth/login', [AuthController::class, 'adminLogin'])
    ->middleware('throttle:5,1');

// 租户管理员登录（公开，无需认证）
Route::post('/console/auth/login', [AuthController::class, 'consoleLogin'])
    ->middleware('throttle:5,1');
