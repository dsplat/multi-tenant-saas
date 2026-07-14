<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Platform\Http\Controllers\AdminSettingsController;

// 管理员后台 - 系统设置
Route::prefix('admin/settings')->group(function () {
    Route::get('/', [AdminSettingsController::class, 'index'])->middleware('rbac.permission:setting.view');
    Route::put('/{group}', [AdminSettingsController::class, 'update'])->middleware('rbac.permission:setting.update');
});
