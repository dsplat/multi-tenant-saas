<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Storage\Http\Controllers\FileController;

Route::prefix('tenant/files')->group(function () {
    Route::get('/', [FileController::class, 'index'])->middleware('rbac.permission:file.upload');
    Route::post('/', [FileController::class, 'store'])->middleware('rbac.permission:file.upload');
    Route::get('/usage', [FileController::class, 'usage'])->middleware('rbac.permission:file.upload');
    Route::get('/{id}', [FileController::class, 'show'])->middleware('rbac.permission:file.upload');
    Route::delete('/{id}', [FileController::class, 'destroy'])->middleware('rbac.permission:file.delete');
});
