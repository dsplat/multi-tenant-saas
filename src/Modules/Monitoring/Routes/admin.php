<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Monitoring\Http\Controllers\Admin\MonitoringAdminController;

Route::prefix('monitoring')->group(function () {
    Route::get('/metrics', [MonitoringAdminController::class, 'metrics']);
    Route::get('/alerts', [MonitoringAdminController::class, 'alerts']);
    Route::get('/health', [MonitoringAdminController::class, 'health']);
});
