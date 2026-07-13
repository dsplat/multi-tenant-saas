<?php

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stats = $this->getStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    protected function getStats(): array
    {
        $stats = [
            ['label' => '用户总数', 'value' => $this->countTable('users'), 'key' => 'users'],
            ['label' => '租户总数', 'value' => $this->countTable('tenants'), 'key' => 'tenants'],
        ];

        // 项目侧可通过 config('tenancy.admin_dashboard_cards') 追加自定义统计卡片
        $customCards = config('tenancy.admin_dashboard_cards', []);
        $stats = array_merge($stats, $customCards);

        return $stats;
    }

    protected function countTable(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
