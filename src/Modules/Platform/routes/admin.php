<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Platform\Http\Controllers\AdminSettingsController;

// 管理员后台 - 系统设置
Route::prefix('admin/settings')->group(function () {
    Route::get('/', [AdminSettingsController::class, 'index']);
    Route::put('/{group}', [AdminSettingsController::class, 'update']);
});
