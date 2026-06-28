<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\UsageRecord;
use MultiTenantSaas\Services\RateLimitService;
use MultiTenantSaas\Services\Traits\ResolvesPlan;

/**
 * 用量计量服务
 *
 * 负责按量计费场景下的用量记录、聚合、查询、超额判定与限流联动。
 *
 * - period 计费周期格式 YYYYMM（年月），默认当前月
 * - 用量记录通过 usage_records 表存储，按 [tenant_id, metric_type, period] 复合索引高效查询
 * - 超额判定读取 SubscriptionPlan.metered_price JSON 规则，支持三种模式：
 *   1) 无规则：放行
 *   2) 简单限额 + 超额单价：超限部分按 overage_price 计费，硬限制下拒绝
 *   3) 阶梯定价：按 tiers 数组逐级累进计价
 * - 限流联动读取 SubscriptionPlan.rate_limit_rpm，调用 RateLimitService::dynamicLimit() 动态调整
 *
 * 租户隔离通过显式 tenant_id 参数管理，支持管理端跨租户查询。
 */
class UsageService
{
    use ResolvesPlan;
    /**
     * 记录用量
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $metric  指标类型（如 api_calls、storage_mb、tokens）
     * @param  float  $value  本次用量
     * @param  string|null  $period  计费周期 YYYYMM，默认当前月
     */
    public static function record(int $tenantId, string $metric, float $value, ?string $period = null): UsageRecord
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Usage value must be non-negative.');
        }

        $period = $period ?: now()->format('Ym');

        return UsageRecord::create([
            'tenant_id' => $tenantId,
            'metric_type' => $metric,
            'value' => $value,
            'period' => $period,
            'recorded_at' => now(),
        ]);
    }

    /**
     * 聚合指定周期的总用量
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $metric  指标类型
     * @param  string  $period  计费周期 YYYYMM
     * @return array{total: float, count: int, metric: string, period: string}
     */
    public static function aggregate(int $tenantId, string $metric, string $period): array
    {
        $row = UsageRecord::where('tenant_id', $tenantId)
            ->where('metric_type', $metric)
            ->where('period', $period)
            ->selectRaw('COALESCE(SUM(value), 0) AS total, COUNT(*) AS aggregate_count')
            ->first();

        return [
            'total' => (float) ($row->total ?? 0),
            'count' => (int) ($row->aggregate_count ?? 0),
            'metric' => $metric,
            'period' => $period,
        ];
    }

    /**
     * 按条件查询用量记录
     *
     * @param  int  $tenantId  租户 ID
     * @param  string|null  $metric  指标类型筛选，null=全部
     * @param  string|null  $periodFrom  起始周期 YYYYMM（含）
     * @param  string|null  $periodTo  截止周期 YYYYMM（含）
     * @return Collection<int, UsageRecord>
     */
    public static function query(int $tenantId, ?string $metric = null, ?string $periodFrom = null, ?string $periodTo = null): Collection
    {
        $query = UsageRecord::where('tenant_id', $tenantId);

        if ($metric !== null) {
            $query->where('metric_type', $metric);
        }
        if ($periodFrom !== null) {
            $query->where('period', '>=', $periodFrom);
        }
        if ($periodTo !== null) {
            $query->where('period', '<=', $periodTo);
        }

        return $query->orderByDesc('recorded_at')->get();
    }

    /**
     * 检查本次用量是否超额并计算费用
     *
     * 读取 SubscriptionPlan.metered_price JSON 阶梯规则，返回 {allowed, overage, price}：
     * - 无规则：放行，overage=0, price=0
     * - 简单限额：超限且硬限制 → allowed=false；超限且软限制 → 返回超额部分与费用
     * - 阶梯定价（tiers）：按阶梯累进计算 price，max_limit 突破且硬限制时 allowed=false
     *
     * metered_price JSON 约定：
     *   简单：{"limit": 1000, "overage_price": 0.05, "hard_limit": false}
     *   阶梯：{"tiers": [{"up_to": 1000, "price": 0}, {"up_to": null, "price": 0.03}], "hard_limit": false, "max_limit": 10000}
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $metric  指标类型
     * @param  float  $value  待计入的本次用量
     * @return array{allowed: bool, overage: float, price: float}
     */
    public static function checkOverage(int $tenantId, string $metric, float $value): array
    {
        $plan = static::resolveCurrentPlan($tenantId);

        if (! $plan) {
            return ['allowed' => false, 'overage' => 0.0, 'price' => 0.0];
        }

        $rules = $plan->metered_price ?? [];

        if (empty($rules)) {
            return ['allowed' => true, 'overage' => 0.0, 'price' => 0.0];
        }

        $currentPeriod = now()->format('Ym');
        $currentUsage = static::aggregate($tenantId, $metric, $currentPeriod)['total'];
        $projectedUsage = $currentUsage + $value;

        // 阶梯定价模式
        if (isset($rules['tiers']) && is_array($rules['tiers'])) {
            return static::evaluateTieredRules($rules, $plan, $currentUsage, $value, $projectedUsage);
        }

        // 简单限额 + 超额单价模式
        return static::evaluateFlatRules($rules, $plan, $projectedUsage);
    }

    /**
     * 读取租户当前 RPM 上限（按系统负载动态调整）
     *
     * 取 SubscriptionPlan.rate_limit_rpm 作为基础值，调用 RateLimitService（实例方法）
     * 的 dynamicLimit() 进行动态调整。仅读不写。
     *
     * @return int 当前租户可用的每分钟请求数上限
     */
    public static function enforceRateLimit(int $tenantId): int
    {
        $plan = static::resolveCurrentPlan($tenantId);
        $baseLimit = (int) ($plan?->rate_limit_rpm ?? 60);

        $service = new RateLimitService;

        return $service->dynamicLimit($baseLimit);
    }



    /**
     * 评估简单限额规则
     *
     * @param  array  $rules  metered_price JSON
     * @param  SubscriptionPlan|null  $plan  计划实例（用于回退读取 overage_allowed / overage_price）
     * @param  float  $projectedUsage  当前周期累计 + 本次用量
     * @return array{allowed: bool, overage: float, price: float}
     */
    protected static function evaluateFlatRules(array $rules, ?SubscriptionPlan $plan, float $projectedUsage): array
    {
        $limit = (float) ($rules['limit'] ?? 0);
        $overagePrice = (float) ($rules['overage_price'] ?? $plan?->overage_price ?? 0);
        $overageAllowed = (bool) ($rules['overage_allowed'] ?? $plan?->overage_allowed ?? false);
        $hardLimit = (bool) ($rules['hard_limit'] ?? ! $overageAllowed);

        if ($projectedUsage <= $limit) {
            return ['allowed' => true, 'overage' => 0.0, 'price' => 0.0];
        }

        $overage = $projectedUsage - $limit;

        if ($hardLimit) {
            return ['allowed' => false, 'overage' => round($overage, 4), 'price' => 0.0];
        }

        $price = $overage * $overagePrice;

        return ['allowed' => true, 'overage' => round($overage, 4), 'price' => round($price, 4)];
    }

    /**
     * 评估阶梯定价规则
     *
     * tiers 结构：[{"up_to": 1000, "price": 0}, {"up_to": 5000, "price": 0.05}, {"up_to": null, "price": 0.03}]
     * - up_to=null 表示无上限（兜底阶梯）
     * - price 为该阶梯单位价格
     *
     * 计费方式：仅对本次增量（currentUsage → projectedUsage）按阶梯累进计价
     *
     * @param  array  $rules  metered_price JSON
     * @param  SubscriptionPlan|null  $plan  计划实例
     * @param  float  $currentUsage  当前周期累计用量
     * @param  float  $value  本次增量
     * @param  float  $projectedUsage  累计 + 增量
     * @return array{allowed: bool, overage: float, price: float}
     */
    protected static function evaluateTieredRules(array $rules, ?SubscriptionPlan $plan, float $currentUsage, float $value, float $projectedUsage): array
    {
        $tiers = $rules['tiers'];
        $overageAllowed = (bool) ($rules['overage_allowed'] ?? $plan?->overage_allowed ?? false);
        $hardLimit = (bool) ($rules['hard_limit'] ?? ! $overageAllowed);

        // 按 up_to 升序排列（null 视为无穷大，排末尾）
        usort($tiers, function ($a, $b) {
            $aUp = array_key_exists('up_to', $a) && $a['up_to'] !== null ? (float) $a['up_to'] : INF;
            $bUp = array_key_exists('up_to', $b) && $b['up_to'] !== null ? (float) $b['up_to'] : INF;

            return $aUp <=> $bUp;
        });

        // 取最大上限（最后一个非 null 的 up_to；全为 null 则无硬上限）
        $maxLimit = INF;
        foreach (array_reverse($tiers) as $tier) {
            if (array_key_exists('up_to', $tier) && $tier['up_to'] !== null) {
                $maxLimit = (float) $tier['up_to'];
                break;
            }
        }

        // 超出绝对上限且硬限制 → 拒绝
        if ($maxLimit !== INF && $projectedUsage > $maxLimit && $hardLimit) {
            return ['allowed' => false, 'overage' => round($projectedUsage - $maxLimit, 4), 'price' => 0.0];
        }

        // 按阶梯累进计价（仅对本次增量）
        $price = static::calculateTieredPrice($tiers, $currentUsage, $projectedUsage);

        // overage = 超出最大免费额度的部分（若有），否则 0
        $freeLimit = static::detectFreeLimit($tiers);
        $overage = $freeLimit !== null && $projectedUsage > $freeLimit
            ? round($projectedUsage - $freeLimit, 4)
            : 0.0;

        return ['allowed' => true, 'overage' => $overage, 'price' => round($price, 4)];
    }

    /**
     * 按 tiered 规则计算从 $from 到 $to 的累进价格
     */
    protected static function calculateTieredPrice(array $tiers, float $from, float $to): float
    {
        if ($to <= $from) {
            return 0.0;
        }

        $price = 0.0;
        $cursor = $from;

        foreach ($tiers as $tier) {
            if ($cursor >= $to) {
                break;
            }
            $tierUp = (array_key_exists('up_to', $tier) && $tier['up_to'] !== null) ? (float) $tier['up_to'] : INF;
            $tierPrice = (float) ($tier['price'] ?? 0);

            $tierEnd = min($to, $tierUp);
            if ($tierEnd > $cursor) {
                $price += ($tierEnd - $cursor) * $tierPrice;
                $cursor = $tierEnd;
            }
        }

        return $price;
    }

    /**
     * 检测阶梯中的免费额度（最后一个 price=0 阶梯的 up_to）
     *
     * @return float|null 无免费阶梯时返回 null
     */
    protected static function detectFreeLimit(array $tiers): ?float
    {
        $freeLimit = null;

        foreach ($tiers as $tier) {
            $tierPrice = (float) ($tier['price'] ?? 0);
            if ($tierPrice > 0) {
                break;
            }
            if (array_key_exists('up_to', $tier) && $tier['up_to'] !== null) {
                $freeLimit = (float) $tier['up_to'];
            }
        }

        return $freeLimit;
    }
}
