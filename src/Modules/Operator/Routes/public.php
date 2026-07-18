<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Operator\Http\Controllers\OperatorAuthController;
use MultiTenantSaas\Modules\Operator\Http\Controllers\OperatorController;

/*
|--------------------------------------------------------------------------
| Operator Public Routes
|--------------------------------------------------------------------------
|
| 公开路由（接受邀请等无需认证的操作）
|
*/

Route::post('/operator/accept-invite', [OperatorController::class, 'acceptInvite']);

// Operator 认证公开路由
Route::prefix('operator-auth')->group(function () {
    Route::post('/register', [OperatorAuthController::class, 'register'])
        ->middleware('throttle:3,1');
    Route::post('/login', [OperatorAuthController::class, 'login'])
        ->middleware('throttle:5,1');
    Route::post('/verify-email', [OperatorAuthController::class, 'verifyEmail'])
        ->middleware('throttle:5,1');
    Route::post('/resend-verification', [OperatorAuthController::class, 'resendVerification'])
        ->middleware('throttle:3,1');
    Route::post('/forgot-password', [OperatorAuthController::class, 'forgotPassword'])
        ->middleware('throttle:3,1');
    Route::post('/reset-password', [OperatorAuthController::class, 'resetPassword'])
        ->middleware('throttle:3,1');
});
