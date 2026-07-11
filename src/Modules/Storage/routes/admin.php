<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Storage\Http\Controllers\FileController;

Route::prefix('admin/files')->group(function () {
    Route::get('/', [FileController::class, 'adminIndex']);
    Route::get('/usage', [FileController::class, 'adminUsage']);
    Route::delete('/{id}', [FileController::class, 'adminDestroy']);
});
