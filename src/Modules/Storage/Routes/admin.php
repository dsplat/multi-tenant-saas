<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Storage\Http\Controllers\FileController;

Route::prefix('admin/files')->group(function () {
    Route::get('/', [FileController::class, 'adminIndex'])->middleware('rbac.permission:file.upload');
    Route::get('/usage', [FileController::class, 'adminUsage'])->middleware('rbac.permission:file.upload');
    Route::delete('/{id}', [FileController::class, 'adminDestroy'])->middleware('rbac.permission:file.delete');
});
