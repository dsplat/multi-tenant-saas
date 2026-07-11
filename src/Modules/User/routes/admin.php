<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantMemberController;

// 管理员后台 - 租户管理
Route::prefix('admin/tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index']);
    Route::post('/', [TenantController::class, 'store']);
    Route::get('/{tenantId}', [TenantController::class, 'show']);
    Route::put('/{tenantId}', [TenantController::class, 'update']);
    Route::delete('/{tenantId}', [TenantController::class, 'destroy']);
    Route::post('/{tenantId}/suspend', [TenantController::class, 'suspend']);
    Route::post('/{tenantId}/activate', [TenantController::class, 'activate']);
});

// 管理员后台 - 成员管理
Route::prefix('admin/tenants/{tenantId}/members')->group(function () {
    Route::get('/', [TenantMemberController::class, 'index']);
    Route::post('/', [TenantMemberController::class, 'store']);
    Route::put('/{userId}', [TenantMemberController::class, 'update']);
    Route::delete('/{userId}', [TenantMemberController::class, 'destroy']);
});
