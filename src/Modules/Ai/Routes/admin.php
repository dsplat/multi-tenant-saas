<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Ai\Http\Controllers\AdminAiController;

// 管理员后台 - AI 配置（模型别名 / 目录同步 / 默认模型 / 租户配置 / 连接测试）
Route::prefix('ai')->group(function () {
    Route::middleware('rbac.permission:setting.view')->group(function () {
        Route::get('/aliases', [AdminAiController::class, 'aliasIndex']);
        Route::get('/catalog', [AdminAiController::class, 'catalog']);
        Route::get('/defaults', [AdminAiController::class, 'defaultsIndex']);
        Route::get('/tenants', [AdminAiController::class, 'tenantIndex']);
        Route::get('/tenants/{tenantId}/config', [AdminAiController::class, 'tenantConfigShow']);
    });

    Route::middleware('rbac.permission:setting.update')->group(function () {
        Route::post('/aliases', [AdminAiController::class, 'aliasStore']);
        Route::put('/aliases/{aliasId}', [AdminAiController::class, 'aliasUpdate']);
        Route::delete('/aliases/{aliasId}', [AdminAiController::class, 'aliasDestroy']);
        Route::post('/catalog/sync', [AdminAiController::class, 'catalogSync']);
        Route::put('/defaults', [AdminAiController::class, 'defaultsUpdate']);
        Route::put('/tenants/{tenantId}/config', [AdminAiController::class, 'tenantConfigUpdate']);
        Route::post('/providers/{code}/test', [AdminAiController::class, 'providerTest']);
    });
});
