<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantMemberController;
use MultiTenantSaas\Modules\User\Http\Controllers\UserController;

// 管理员后台 - 租户管理
Route::prefix('tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index'])->middleware('rbac.permission:tenant.view');
    Route::post('/', [TenantController::class, 'store'])->middleware('rbac.permission:tenant.create');
    Route::get('/{tenantId}', [TenantController::class, 'show'])->middleware('rbac.permission:tenant.view');
    Route::put('/{tenantId}', [TenantController::class, 'update'])->middleware('rbac.permission:tenant.update');
    Route::delete('/{tenantId}', [TenantController::class, 'destroy'])->middleware('rbac.permission:tenant.delete');
    Route::post('/{tenantId}/suspend', [TenantController::class, 'suspend'])->middleware('rbac.permission:tenant.suspend');
    Route::post('/{tenantId}/activate', [TenantController::class, 'activate'])->middleware('rbac.permission:tenant.activate');
});

// 管理员后台 - 成员管理
Route::prefix('tenants/{tenantId}/members')->group(function () {
    Route::get('/', [TenantMemberController::class, 'index'])->middleware('rbac.permission:member.view');
    Route::post('/', [TenantMemberController::class, 'store'])->middleware('rbac.permission:member.create');
    Route::put('/{userId}', [TenantMemberController::class, 'update'])->middleware('rbac.permission:member.update');
    Route::delete('/{userId}', [TenantMemberController::class, 'destroy'])->middleware('rbac.permission:member.delete');
});

// 管理员后台 - 用户管理
Route::prefix('tenants/{tenantId}/users')->group(function () {
    Route::get('/', [UserController::class, 'adminIndex'])->middleware('rbac.permission:user.view');
    Route::get('/search', [UserController::class, 'search'])->middleware('rbac.permission:user.view');
    Route::get('/{userId}', [UserController::class, 'show'])->middleware('rbac.permission:user.view');
    Route::put('/{userId}', [UserController::class, 'update'])->middleware('rbac.permission:user.update');
    Route::delete('/{userId}', [UserController::class, 'destroy'])->middleware('rbac.permission:user.delete');
});

// 管理员后台 - 全局用户搜索
Route::prefix('users')->group(function () {
    Route::get('/search', [UserController::class, 'globalSearch'])->middleware('rbac.permission:user.view');
});
