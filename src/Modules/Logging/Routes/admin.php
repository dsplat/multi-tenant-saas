<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Logging\Http\Controllers\TenantAuditController;

// 管理员后台 - 审计日志
Route::prefix('admin/audit')->group(function () {
    Route::get('/logs', [TenantAuditController::class, 'index']);
    Route::get('/logs/{id}', [TenantAuditController::class, 'show']);
    Route::get('/logs/export', [TenantAuditController::class, 'export']);
});
