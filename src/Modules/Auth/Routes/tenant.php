<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\MfaController;
use MultiTenantSaas\Modules\Auth\Http\Controllers\TenantOAuthController;

// 租户后台 - MFA 管理（用户管理自己的 MFA，无需权限中间件）
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
    Route::get('/config', [TenantOAuthController::class, 'getOAuthConfig'])->middleware('rbac.permission:setting.update');
    Route::put('/{provider}', [TenantOAuthController::class, 'updateOAuthConfig'])->middleware('rbac.permission:setting.update');
});
