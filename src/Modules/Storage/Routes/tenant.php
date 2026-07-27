<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Storage\Http\Controllers\FileController;
use MultiTenantSaas\Modules\Storage\Http\Controllers\StorageConfigController;

Route::prefix('tenant/storage')->group(function () {
    Route::get('/config', [StorageConfigController::class, 'show'])->middleware('rbac.permission:setting.view');
    Route::put('/config', [StorageConfigController::class, 'update'])->middleware('rbac.permission:setting.update');
});

Route::prefix('tenant/files')->group(function () {
    Route::get('/', [FileController::class, 'index'])->middleware('rbac.permission:file.upload');
    Route::post('/', [FileController::class, 'store'])->middleware('rbac.permission:file.upload');
    Route::get('/usage', [FileController::class, 'usage'])->middleware('rbac.permission:file.upload');
    Route::get('/{id}', [FileController::class, 'show'])->middleware('rbac.permission:file.upload');
    Route::delete('/{id}', [FileController::class, 'destroy'])->middleware('rbac.permission:file.delete');
});
