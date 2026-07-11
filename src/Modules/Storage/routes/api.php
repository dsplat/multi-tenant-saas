<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Storage\Http\Controllers\FileController;

Route::get('/files', [FileController::class, 'index']);
Route::post('/files', [FileController::class, 'store']);
Route::get('/files/usage', [FileController::class, 'usage']);
Route::get('/files/{id}', [FileController::class, 'show']);
Route::get('/files/{id}/preview', [FileController::class, 'preview']);
Route::get('/files/{id}/download', [FileController::class, 'download']);
Route::post('/files/{id}/share', [FileController::class, 'share']);
Route::delete('/files/{id}', [FileController::class, 'destroy']);
