<?php

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;

class ConsoleDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $stats = $this->getStats($tenantId);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    protected function getStats(?int $tenantId): array
    {
        $stats = [
            ['label' => '成员数', 'value' => $this->countTenantUsers($tenantId), 'key' => 'members'],
        ];

        $customCards = config('tenancy.console_dashboard_cards', []);
        $stats = array_merge($stats, $customCards);

        return $stats;
    }

    protected function countTenantUsers(?int $tenantId): int
    {
        if (! $tenantId) {
            return 0;
        }

        try {
            return DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
