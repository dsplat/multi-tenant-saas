<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\ModuleController;

// 管理员后台 - 模块管理
Route::prefix('admin/modules')->group(function () {
    Route::get('/', [ModuleController::class, 'index']);
    Route::post('/{name}/enable', [ModuleController::class, 'enable']);
    Route::post('/{name}/disable', [ModuleController::class, 'disable']);
});
