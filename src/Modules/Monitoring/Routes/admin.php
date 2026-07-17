<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Services\AlertService;
use MultiTenantSaas\Modules\Infrastructure\Services\MetricsService;

Route::prefix('admin/monitoring')->group(function () {
    Route::get('/metrics', function () {
        $service = app(MetricsService::class);

        return response()->json([
            'success' => true,
            'data' => [
                'qps' => $service->getQps(),
                'rpm' => $service->getRpm(),
                'error_rate' => $service->getErrorRate(),
                'active_tenants' => $service->getActiveTenants(),
                'active_users' => $service->getActiveUsers(),
            ],
        ]);
    });
    Route::get('/alerts', function () {
        $service = app(AlertService::class);

        return response()->json(['success' => true, 'data' => $service->history()]);
    });
    Route::get('/health', function () {
        return response()->json(['success' => true, 'status' => 'healthy']);
    });
});
