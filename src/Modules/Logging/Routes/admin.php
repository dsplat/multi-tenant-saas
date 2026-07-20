<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Logging\Http\Controllers\TenantAuditController;

// 管理员后台 - 审计日志
Route::prefix('audit')->group(function () {
    Route::get('/logs', [TenantAuditController::class, 'index'])->middleware('rbac.permission:audit.view');
    Route::get('/logs/{id}', [TenantAuditController::class, 'show'])->middleware('rbac.permission:audit.view');
    Route::get('/logs/export', [TenantAuditController::class, 'export'])->middleware('rbac.permission:audit.view');
});
