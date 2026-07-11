<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Services\MetricsService;

Route::prefix('tenant/monitoring')->group(function () {
    Route::get('/metrics', function () {
        $service = app(MetricsService::class);

        return response()->json(['success' => true, 'data' => $service->getTenantMetrics()]);
    });
});
