<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Storage\Http\Controllers\FileController;

Route::prefix('tenant/files')->group(function () {
    Route::get('/', [FileController::class, 'index']);
    Route::post('/', [FileController::class, 'store']);
    Route::get('/usage', [FileController::class, 'usage']);
    Route::get('/{id}', [FileController::class, 'show']);
    Route::delete('/{id}', [FileController::class, 'destroy']);
});
