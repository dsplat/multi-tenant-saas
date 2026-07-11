<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\AuthController;
use MultiTenantSaas\Modules\Auth\Http\Controllers\MfaController;

// 租户后台 - MFA 管理
Route::prefix('tenant/auth')->group(function () {
    Route::get('/mfa/devices', [MfaController::class, 'devices']);
    Route::delete('/mfa/devices/{deviceId}', [MfaController::class, 'destroyDevice']);
    Route::put('/mfa/devices/{deviceId}', [MfaController::class, 'renameDevice']);
    Route::post('/mfa/devices/{deviceId}/primary', [MfaController::class, 'setPrimary']);
    Route::post('/mfa/recovery-codes/generate', [MfaController::class, 'generateRecoveryCodes']);
    Route::get('/mfa/recovery-codes/status', [MfaController::class, 'recoveryCodeStatus']);
    Route::get('/mfa/sessions', [MfaController::class, 'sessions']);
    Route::delete('/mfa/sessions/{sessionId}', [MfaController::class, 'revokeSession']);
    Route::post('/mfa/sessions/revoke-all', [MfaController::class, 'revokeAllSessions']);
});

// 租户后台 - OAuth 配置
Route::prefix('tenant/auth/oauth')->group(function () {
    Route::get('/config', [AuthController::class, 'getOAuthConfig']);
    Route::put('/{provider}', [AuthController::class, 'updateOAuthConfig']);
});
