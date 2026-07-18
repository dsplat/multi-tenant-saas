<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Platform\Http\Controllers\AdminApplicationController;
use MultiTenantSaas\Modules\Platform\Http\Controllers\AdminSettingsController;
use MultiTenantSaas\Modules\Platform\Http\Controllers\ApplyFieldConfigController;

// 管理员后台 - 系统设置
Route::prefix('settings')->group(function () {
    Route::get('/', [AdminSettingsController::class, 'index'])->middleware('rbac.permission:setting.view');
    Route::put('/{group}', [AdminSettingsController::class, 'update'])->middleware('rbac.permission:setting.update');
});

// 管理员后台 - 租户申请审批
Route::prefix('applications')->group(function () {
    Route::get('/', [AdminApplicationController::class, 'index'])->middleware('rbac.permission:application.view');
    Route::get('/{id}', [AdminApplicationController::class, 'show'])->middleware('rbac.permission:application.view');
    Route::post('/{id}/approve', [AdminApplicationController::class, 'approve'])->middleware('rbac.permission:application.approve');
    Route::post('/{id}/reject', [AdminApplicationController::class, 'reject'])->middleware('rbac.permission:application.reject');
});

// 管理员后台 - 申请字段配置
Route::prefix('apply-fields')->group(function () {
    Route::get('/', [ApplyFieldConfigController::class, 'index'])->middleware('rbac.permission:apply_fields.view');
    Route::put('/', [ApplyFieldConfigController::class, 'update'])->middleware('rbac.permission:apply_fields.update');
});
