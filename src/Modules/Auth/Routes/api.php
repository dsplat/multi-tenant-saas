<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\AuthController;
use MultiTenantSaas\Modules\Auth\Http\Controllers\MfaController;
use MultiTenantSaas\Modules\Auth\Http\Controllers\UserProfileController;

// 认证路由（需要 auth:sanctum）
Route::prefix('auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/mfa/verify', [AuthController::class, 'mfaVerify']);

    // 用户资料管理
    Route::put('/profile', [UserProfileController::class, 'updateProfile']);
    Route::put('/password', [UserProfileController::class, 'changePassword']);
    Route::get('/oauth-bindings', [UserProfileController::class, 'oauthBindings']);
    Route::delete('/oauth-bindings/{provider}', [UserProfileController::class, 'unbindOAuth']);
});

// 管理员认证路由（需要 auth:sanctum）
Route::prefix('admin/auth')->group(function () {
    Route::get('/user', [AuthController::class, 'adminUser']);
    Route::post('/logout', [AuthController::class, 'adminLogout']);
});

// 租户管理员认证路由（需要 auth:sanctum）
Route::prefix('console/auth')->group(function () {
    Route::get('/user', [AuthController::class, 'consoleUser']);
    Route::post('/logout', [AuthController::class, 'consoleLogout']);
});

// MFA 管理路由
Route::prefix('mfa')->group(function () {
    Route::post('/totp/setup', [MfaController::class, 'setupTotp']);
    Route::post('/totp/confirm', [MfaController::class, 'confirmTotp']);
    Route::post('/email/send', [MfaController::class, 'sendEmailCode']);
    Route::post('/sms/send', [MfaController::class, 'sendSmsCode']);
    Route::get('/devices', [MfaController::class, 'devices']);
    Route::delete('/devices/{deviceId}', [MfaController::class, 'destroyDevice']);
    Route::put('/devices/{deviceId}', [MfaController::class, 'renameDevice']);
    Route::post('/devices/{deviceId}/primary', [MfaController::class, 'setPrimary']);
    Route::post('/recovery-codes/generate', [MfaController::class, 'generateRecoveryCodes']);
    Route::get('/recovery-codes/status', [MfaController::class, 'recoveryCodeStatus']);
    Route::get('/sessions', [MfaController::class, 'sessions']);
    Route::delete('/sessions/{sessionId}', [MfaController::class, 'revokeSession']);
    Route::post('/sessions/revoke-all', [MfaController::class, 'revokeAllSessions']);
});
