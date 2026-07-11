<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\AuthController;
use MultiTenantSaas\Modules\Auth\Http\Controllers\RbacController;

// 管理员后台 - RBAC 管理
Route::prefix('admin/auth')->group(function () {
    Route::get('/permissions', [RbacController::class, 'permissions']);
    Route::get('/roles', [RbacController::class, 'roles']);
    Route::post('/roles', [RbacController::class, 'storeRole']);
    Route::put('/roles/{roleId}/permissions', [RbacController::class, 'updateRolePermissions']);
    Route::delete('/roles/{roleId}', [RbacController::class, 'destroyRole']);
    Route::post('/members/{userId}/role', [RbacController::class, 'assignMemberRole']);
});

// 管理员后台 - SSO 管理
Route::prefix('admin/auth/sso')->group(function () {
    Route::get('/providers', [AuthController::class, 'ssoProviders']);
    Route::post('/providers', [AuthController::class, 'storeSsoProvider']);
    Route::delete('/providers/{name}', [AuthController::class, 'destroySsoProvider']);
});
