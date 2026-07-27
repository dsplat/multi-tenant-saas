<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Knowledge\Http\Controllers\ExternalKbConnectionController;

Route::prefix('tenant/external-kb')->group(function () {
    Route::get('/connections', [ExternalKbConnectionController::class, 'index'])->middleware('rbac.permission:setting.view');
    Route::post('/connections', [ExternalKbConnectionController::class, 'store'])->middleware('rbac.permission:setting.update');
    Route::put('/connections/{connectionId}', [ExternalKbConnectionController::class, 'update'])->middleware('rbac.permission:setting.update');
    Route::delete('/connections/{connectionId}', [ExternalKbConnectionController::class, 'destroy'])->middleware('rbac.permission:setting.update');
    Route::post('/connections/{connectionId}/test', [ExternalKbConnectionController::class, 'test'])->middleware('rbac.permission:setting.view');
});
