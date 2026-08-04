<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Monitoring\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Modules\Infrastructure\Services\AlertService;
use MultiTenantSaas\Modules\Infrastructure\Services\MetricsService;

/**
 * 平台管理端：监控指标与告警
 */
class MonitoringAdminController extends Controller
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
            ],
        ]);
    }

    public function alerts()
    {
        $service = app(AlertService::class);

        return response()->json(['success' => true, 'data' => $service->history()]);
    }

    public function health()
    {
        return response()->json(['success' => true, 'status' => 'healthy']);
    }
}
