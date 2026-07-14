<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\ModuleController;

// 管理员后台 - 模块管理
Route::prefix('admin/modules')->group(function () {
    Route::get('/', [ModuleController::class, 'index'])->middleware('rbac.permission:setting.view');
    Route::post('/{name}/enable', [ModuleController::class, 'enable'])->middleware('rbac.permission:setting.update');
    Route::post('/{name}/disable', [ModuleController::class, 'disable'])->middleware('rbac.permission:setting.update');
});
