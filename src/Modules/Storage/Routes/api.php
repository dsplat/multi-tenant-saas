<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Storage\Http\Controllers\FileController;

// 通用文件操作
Route::middleware('rbac.permission:file.upload')->group(function () {
    Route::get('/files', [FileController::class, 'index']);
    Route::post('/files', [FileController::class, 'store']);
    Route::get('/files/usage', [FileController::class, 'usage']);
    Route::get('/files/{id}', [FileController::class, 'show']);
    Route::get('/files/{id}/preview', [FileController::class, 'preview']);
    Route::get('/files/{id}/download', [FileController::class, 'download']);
    Route::post('/files/{id}/share', [FileController::class, 'share']);
    Route::post('/files/entity/{module}/{entityId}', [FileController::class, 'uploadForEntity']);
    Route::get('/files/entity/{module}/{entityId}', [FileController::class, 'getForEntity']);
    Route::get('/files/entity/{module}/{entityId}/url', [FileController::class, 'getUrlForEntity']);
});

Route::middleware('rbac.permission:file.delete')->group(function () {
    Route::delete('/files/{id}', [FileController::class, 'destroy']);
    Route::delete('/files/entity/{module}/{entityId}', [FileController::class, 'deleteForEntity']);
});
