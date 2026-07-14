<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\ModuleController;

// 系统级模块管理
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/admin/modules', [ModuleController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/admin/modules/{name}/enable', [ModuleController::class, 'enable']);
    Route::post('/admin/modules/{name}/disable', [ModuleController::class, 'disable']);
});

// 租户级模块管理
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tenants/{tenantId}/modules', [ModuleController::class, 'tenantIndex']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/tenants/{tenantId}/modules/{name}/enable', [ModuleController::class, 'tenantEnable']);
    Route::post('/tenants/{tenantId}/modules/{name}/disable', [ModuleController::class, 'tenantDisable']);
});
