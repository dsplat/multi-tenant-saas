<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Monitoring\Http\Controllers\MonitoringTenantController;

Route::prefix('tenant/monitoring')->group(function () {
    Route::get('/metrics', [MonitoringTenantController::class, 'metrics']);
});
