<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Pay\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Pay\Services\SalesConfigService;

/**
 * 销售折现配置（租户级）
 */
class SalesConfigController extends Controller
{
    public function __construct(
        protected SalesConfigService $salesConfigService,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->salesConfigService->getConfig($tenantId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mixed_pay_enabled'       => 'nullable|boolean',
            'points_to_cash_ratio'    => 'nullable|integer|min:1',
            'max_points_deduct_ratio' => 'nullable|integer|min:0|max:100',
        ]);

        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->salesConfigService->updateConfig($tenantId, $validated),
        ]);
    }
}
