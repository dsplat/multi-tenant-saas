<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantMemberController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantSettingController;

// 租户后台 - 租户信息（用户管理自己的 profile，无需权限中间件）
Route::prefix('tenant')->group(function () {
    Route::get('/profile', [TenantController::class, 'show']);
    Route::put('/profile', [TenantController::class, 'update']);
    Route::get('/settings/{group?}', [TenantSettingController::class, 'index'])->middleware('rbac.permission:setting.view');
    Route::put('/settings/{group}', [TenantSettingController::class, 'update'])->middleware('rbac.permission:setting.update');
});

// 租户后台 - 成员管理
Route::prefix('tenant/members')->group(function () {
    Route::get('/', [TenantMemberController::class, 'index'])->middleware('rbac.permission:member.view');
    Route::post('/', [TenantMemberController::class, 'store'])->middleware('rbac.permission:member.create');
    Route::put('/{userId}', [TenantMemberController::class, 'update'])->middleware('rbac.permission:member.update');
    Route::delete('/{userId}', [TenantMemberController::class, 'destroy'])->middleware('rbac.permission:member.delete');
});
