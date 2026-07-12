<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantMemberController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantSettingController;

// 租户后台 - 租户信息
Route::prefix('tenant')->group(function () {
    Route::get('/profile', [TenantController::class, 'show']);
    Route::put('/profile', [TenantController::class, 'update']);
    Route::get('/settings/{group?}', [TenantSettingController::class, 'index']);
    Route::put('/settings/{group}', [TenantSettingController::class, 'update']);
});

// 租户后台 - 成员管理
Route::prefix('tenant/members')->group(function () {
    Route::get('/', [TenantMemberController::class, 'index']);
    Route::post('/', [TenantMemberController::class, 'store']);
    Route::put('/{userId}', [TenantMemberController::class, 'update']);
    Route::delete('/{userId}', [TenantMemberController::class, 'destroy']);
});
