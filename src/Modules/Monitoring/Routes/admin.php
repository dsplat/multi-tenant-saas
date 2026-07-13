<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Services\AlertService;
use MultiTenantSaas\Modules\Infrastructure\Services\MetricsService;

Route::prefix('admin/monitoring')->group(function () {
    Route::get('/metrics', function () {
        $service = app(MetricsService::class);

        return response()->json(['success' => true, 'data' => $service->getMetrics()]);
    });
    Route::get('/alerts', function () {
        $service = app(AlertService::class);

        return response()->json(['success' => true, 'data' => $service->getActiveAlerts()]);
    });
    Route::get('/health', function () {
        return response()->json(['success' => true, 'status' => 'healthy']);
    });
});
