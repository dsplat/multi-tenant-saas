<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Operator\Http\Controllers\OperatorAuthController;
use MultiTenantSaas\Modules\Operator\Http\Controllers\OperatorController;

/*
|--------------------------------------------------------------------------
| Operator SPA API Routes
|--------------------------------------------------------------------------
|
| SPA 前端调用的 API 路由（auth:sanctum）
|
*/

// Operator 认证路由（需 Sanctum token）
Route::prefix('operator-auth')->group(function () {
    Route::get('/me', [OperatorAuthController::class, 'me']);
    Route::post('/logout', [OperatorAuthController::class, 'logout']);
});

Route::prefix('operators')->group(function () {
    Route::get('/', [OperatorController::class, 'index'])->middleware('rbac.permission:member.view');
    Route::get('/{operatorId}', [OperatorController::class, 'show'])->middleware('rbac.permission:member.view');
    Route::put('/{operatorId}', [OperatorController::class, 'update'])->middleware('rbac.permission:member.update');
    Route::post('/invite', [OperatorController::class, 'invite'])->middleware('rbac.permission:member.create');
    Route::put('/{operatorId}/role', [OperatorController::class, 'updateRole'])->middleware('rbac.permission:member.update');
    Route::delete('/{operatorId}', [OperatorController::class, 'remove'])->middleware('rbac.permission:member.delete');
    Route::get('/{operatorId}/tenants', [OperatorController::class, 'tenants'])->middleware('rbac.permission:member.view');
    Route::post('/{operatorId}/toggle-status', [OperatorController::class, 'toggleStatus'])->middleware('rbac.permission:member.update');
    Route::post('/{operatorId}/resend-invite', [OperatorController::class, 'resendInvite'])->middleware('rbac.permission:member.create');
});
