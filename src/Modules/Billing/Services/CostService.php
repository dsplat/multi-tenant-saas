<?php

namespace MultiTenantSaas\Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Billing\Models\CostAllocation;
use Throwable;

/**
 * 租户成本分摊服务
 *
 * 提供租户级成本分摊与盈亏分析能力：
 *  - 基础设施成本分摊（计算/存储/带宽）
 *  - AI 用量成本（与 AiUsageService 联动，自动归入租户成本）
 *  - 第三方服务成本
 *  - 租户级盈亏分析（收入 - 成本）
 *  - 成本趋势预测（线性回归）
 *  - 月度成本报表
 *
 * 成本数据存储在 cost_allocations 表，按月（YYYY-MM）聚合。
 *
 * 依赖：TenantContextContract（租户上下文）、AiUsageService（AI 用量联动）。
 */
class CostService
{
    public function __construct(
        protected TenantContextContract $tenantContext,
        protected AiUsageService $aiUsageService,
    ) {}

    /**
     * 基础设施成本分摊
     *
     * @param  string  $subtype  子类型（compute/storage/bandwidth）
     * @param  float  $amount  金额
     * @param  string  $period  计费周期（YYYY-MM）
     * @param  string  $basis  分摊依据（by_users/by_storage/by_requests）
     * @param  float|null  $basisValue  分摊依据量化值
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @param  array<string, mixed>  $metadata  附加元数据
     */
    public function allocateInfrastructureCost(
        string $subtype,
        float $amount,
        string $period,
        string $basis,
        ?float $basisValue = null,
        ?int $tenantId = null,
        array $metadata = [],
    ): CostAllocation {
        return CostAllocation::create([
            'tenant_id' => $this->resolveTenantId($tenantId),
            'cost_type' => CostAllocation::TYPE_INFRASTRUCTURE,
            'cost_subtype' => $subtype,
            'amount' => max(0.0, $amount),
            'currency' => (string) config('tenancy.cost_tracking.default_currency', 'CNY'),
            'period' => $period,
            'allocation_basis' => $basis,
            'allocation_value' => $basisValue,
            'metadata' => array_merge(['subtype' => $subtype], $metadata),
        ]);
    }

    /**
     * AI 用量成本归入（与 AiUsageService 联动）
     *
     * 从 ai_requests 表聚合指定周期内成功的 AI 调用费用，写入成本分摊记录。
     * 同时调用 AiUsageService 获取用量明细作为元数据。
     *
     * @param  string|null  $period  计费周期（默认当前月）
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @return CostAllocation|null 无成本时返回 null
     */
    public function allocateAiCost(?string $period = null, ?int $tenantId = null): ?CostAllocation
    {
        $period = $period ?? now()->format('Y-m');
        $tid = $this->resolveTenantId($tenantId);

        if (! Schema::hasTable('ai_requests')) {
            return null;
        }

        [$start, $end] = $this->periodRange($period);

        $query = DB::table('ai_requests')
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end]);

        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        $amount = (float) $query->sum('cost');

        if ($amount <= 0) {
            return null;
        }

        // 按模型聚合明细
        $byModel = DB::table('ai_requests')
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end])
            ->when($tid !== null, fn ($q) => $q->where('tenant_id', $tid))
            ->select('model', DB::raw('SUM(cost) as total_cost'), DB::raw('COUNT(*) as request_count'))
            ->groupBy('model')
            ->get()
            ->map(fn ($r) => [
                'model' => $r->model,
                'cost' => (float) $r->total_cost,
                'requests' => (int) $r->request_count,
            ])
            ->all();

        // 联动 AiUsageService 获取配额摘要（可选，失败不影响主流程）
        $usageSummary = null;
        try {
            $usageSummary = $this->aiUsageService->getUsageSummary();
        } catch (Throwable $e) {
            Log::warning('[CostService] AiUsageService getUsageSummary failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return CostAllocation::create([
            'tenant_id' => $tid,
            'cost_type' => CostAllocation::TYPE_AI_USAGE,
            'cost_subtype' => null,
            'amount' => round($amount, 4),
            'currency' => (string) config('tenancy.cost_tracking.default_currency', 'CNY'),
            'period' => $period,
            'allocation_basis' => 'by_ai_usage',
            'allocation_value' => (float) array_sum(array_column($byModel, 'requests')),
            'metadata' => [
                'by_model' => $byModel,
                'usage_summary' => $usageSummary,
            ],
        ]);
    }

    /**
     * 第三方服务成本分摊
     *
     * @param  string  $service  第三方服务名
     * @param  float  $amount  金额
     * @param  string  $period  计费周期（YYYY-MM）
     * @param  string  $basis  分摊依据
     * @param  float|null  $basisValue  分摊依据量化值
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @param  array<string, mixed>  $metadata  附加元数据
     */
    public function allocateThirdPartyCost(
        string $service,
        float $amount,
        string $period,
        string $basis,
        ?float $basisValue = null,
        ?int $tenantId = null,
        array $metadata = [],
    ): CostAllocation {
        return CostAllocation::create([
            'tenant_id' => $this->resolveTenantId($tenantId),
            'cost_type' => CostAllocation::TYPE_THIRD_PARTY,
            'cost_subtype' => $service,
            'amount' => max(0.0, $amount),
            'currency' => (string) config('tenancy.cost_tracking.default_currency', 'CNY'),
            'period' => $period,
            'allocation_basis' => $basis,
            'allocation_value' => $basisValue,
            'metadata' => array_merge(['service' => $service], $metadata),
        ]);
    }

    /**
     * 租户级盈亏分析
     *
     * 收入来自 financial_records（status=paid），成本来自 cost_allocations。
     *
     * @param  string|null  $period  计费周期（默认当前月）
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @return array{
     *     period: string,
     *     revenue: float,
     *     total_cost: float,
     *     profit: float,
     *     cost_breakdown: array<string,float>
     * }
     */
    public function getProfitLoss(?string $period = null, ?int $tenantId = null): array
    {
        $period = $period ?? now()->format('Y-m');
        $tid = $this->resolveTenantId($tenantId);

        $revenue = $this->aggregateRevenue($period, $tid);
        $costBreakdown = $this->aggregateCostsByType($period, $tid);
        $totalCost = (float) array_sum($costBreakdown);
        $profit = round($revenue - $totalCost, 4);

        return [
            'period' => $period,
            'revenue' => round($revenue, 4),
            'total_cost' => round($totalCost, 4),
            'profit' => $profit,
            'cost_breakdown' => $costBreakdown,
        ];
    }

    /**
     * 成本趋势预测（基于历史月度成本的线性回归）
     *
     * @param  int  $months  预测未来月数
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @return array{
     *     history: array<int,array{period: string, cost: float}>,
     *     forecast: array<int,array{period: string, cost: float}>,
     *     avg_monthly_cost: float,
     *     growth_rate: float
     * }
     */
    public function forecastCostTrend(int $months = 3, ?int $tenantId = null): array
    {
        $tid = $this->resolveTenantId($tenantId);
        $historyMonths = (int) config('tenancy.cost_tracking.history_months', 6);

        $history = [];
        for ($i = $historyMonths - 1; $i >= 0; $i--) {
            $period = now()->subMonths($i)->format('Y-m');
            $cost = $this->aggregateTotalCost($period, $tid);
            $history[] = ['period' => $period, 'cost' => round($cost, 4)];
        }

        $costs = array_map(fn ($h) => $h['cost'], $history);
        [$avg, $growthRate] = $this->computeTrend($costs);
        $forecast = $this->projectForecast($costs, $months);

        return [
            'history' => $history,
            'forecast' => $forecast,
            'avg_monthly_cost' => round($avg, 4),
            'growth_rate' => round($growthRate, 4),
        ];
    }

    /**
     * 月度成本报表
     *
     * 按成本类型与子类型聚合，返回结构化报表。
     *
     * @param  string|null  $period  计费周期（默认当前月）
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @return array{
     *     period: string,
     *     total: float,
     *     by_type: array<string,float>,
     *     by_subtype: array<string,array{type: string, amount: float}>,
     *     records: int
     * }
     */
    public function getMonthlyReport(?string $period = null, ?int $tenantId = null): array
    {
        $period = $period ?? now()->format('Y-m');
        $tid = $this->resolveTenantId($tenantId);

        $query = DB::table('cost_allocations')->where('period', $period);
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }
        $rows = $query->get();

        $byType = [];
        $bySubtype = [];
        foreach ($rows as $r) {
            $type = $r->cost_type;
            $amount = (float) $r->amount;
            $byType[$type] = round(($byType[$type] ?? 0) + $amount, 4);

            if ($r->cost_subtype !== null) {
                $bySubtype[$r->cost_subtype] = [
                    'type' => $type,
                    'amount' => round(($bySubtype[$r->cost_subtype]['amount'] ?? 0) + $amount, 4),
                ];
            }
        }

        return [
            'period' => $period,
            'total' => round(array_sum($byType), 4),
            'by_type' => $byType,
            'by_subtype' => $bySubtype,
            'records' => $rows->count(),
        ];
    }

    // ---------- 内部辅助 ----------

    /**
     * 解析租户 ID（优先使用显式传入，否则取上下文）
     */
    protected function resolveTenantId(?int $tenantId): ?int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $contextId = $this->tenantContext->resolveId();

        return $contextId !== null ? (int) $contextId : null;
    }

    /**
     * 计算周期的起止时间
     *
     * @return array{0: string, 1: string}
     */
    protected function periodRange(string $period): array
    {
        $start = "{$period}-01 00:00:00";
        $end = now()->createFromDate(substr($period, 0, 4), (int) substr($period, 5, 2), 1)
            ->endOfMonth()
            ->format('Y-m-d H:i:s');

        return [$start, $end];
    }

    /**
     * 聚合指定周期的收入（来自 financial_records，status=paid）
     */
    protected function aggregateRevenue(string $period, ?int $tenantId): float
    {
        if (! Schema::hasTable('financial_records')) {
            return 0.0;
        }

        [$start, $end] = $this->periodRange($period);

        $query = DB::table('financial_records')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end]);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return (float) $query->sum('amount');
    }

    /**
     * 按成本类型聚合指定周期的成本
     *
     * @return array<string,float>
     */
    protected function aggregateCostsByType(string $period, ?int $tenantId): array
    {
        $query = DB::table('cost_allocations')->where('period', $period);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $rows = $query->select('cost_type', DB::raw('SUM(amount) as total'))
            ->groupBy('cost_type')
            ->get();

        $breakdown = [];
        foreach ($rows as $r) {
            $breakdown[$r->cost_type] = round((float) $r->total, 4);
        }

        return $breakdown;
    }

    /**
     * 聚合指定周期的总成本
     */
    protected function aggregateTotalCost(string $period, ?int $tenantId): float
    {
        $query = DB::table('cost_allocations')->where('period', $period);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return (float) $query->sum('amount');
    }

    /**
     * 计算趋势指标（平均值与增长率）
     *
     * @param  array<float>  $costs  历史月度成本
     * @return array{0: float, 1: float} [平均月度成本, 增长率]
     */
    protected function computeTrend(array $costs): array
    {
        $costs = array_filter($costs, fn ($c) => $c > 0);
        if (empty($costs)) {
            return [0.0, 0.0];
        }

        $avg = array_sum($costs) / count($costs);
        $values = array_values($costs);

        if (count($values) < 2) {
            return [$avg, 0.0];
        }

        $first = $values[0];
        $last = $values[count($values) - 1];

        $growthRate = $first > 0 ? ($last - $first) / $first : 0.0;

        return [$avg, $growthRate];
    }

    /**
     * 基于线性回归预测未来月度成本
     *
     * @param  array<float>  $costs  历史月度成本
     * @param  int  $months  预测月数
     * @return array<int,array{period: string, cost: float}>
     */
    protected function projectForecast(array $costs, int $months): array
    {
        $costs = array_values(array_filter($costs, fn ($c) => $c >= 0));
        $n = count($costs);
        $base = now();

        if ($n === 0) {
            $forecast = [];
            for ($i = 1; $i <= $months; $i++) {
                $forecast[] = [
                    'period' => $base->copy()->addMonths($i)->format('Y-m'),
                    'cost' => 0.0,
                ];
            }

            return $forecast;
        }

        if ($n === 1) {
            $flat = $costs[0];
            $forecast = [];
            for ($i = 1; $i <= $months; $i++) {
                $forecast[] = [
                    'period' => $base->copy()->addMonths($i)->format('Y-m'),
                    'cost' => round($flat, 4),
                ];
            }

            return $forecast;
        }

        // 简单线性回归：x=0..n-1, y=costs
        $x = range(0, $n - 1);
        $sumX = array_sum($x);
        $sumY = array_sum($costs);
        $sumXY = 0.0;
        $sumXX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $costs[$i];
            $sumXX += $x[$i] * $x[$i];
        }
        $denominator = $n * $sumXX - $sumX * $sumX;
        $slope = $denominator != 0 ? ($n * $sumXY - $sumX * $sumY) / $denominator : 0.0;
        $intercept = ($sumY - $slope * $sumX) / $n;

        $forecast = [];
        for ($i = 1; $i <= $months; $i++) {
            $predicted = max(0.0, $intercept + $slope * ($n - 1 + $i));
            $forecast[] = [
                'period' => $base->copy()->addMonths($i)->format('Y-m'),
                'cost' => round($predicted, 4),
            ];
        }

        return $forecast;
    }
}
