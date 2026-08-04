<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Modules\Infrastructure\Services\MetricsService;

/**
 * 租户端：监控指标（含端点分布）
 */
class MonitoringTenantController extends Controller
{
    public function metrics()
    {
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
    }
}
