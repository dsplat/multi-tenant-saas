<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Services\MetricsService;

Route::prefix('tenant/monitoring')->group(function () {
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
                'endpoint_distribution' => $service->getEndpointDistribution(),
            ],
        ]);
    });
});
