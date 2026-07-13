<?php

namespace MultiTenantSaas\Modules\Billing\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionHistory;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Services\Traits\ResolvesPlan;

/**
 * 计划变更服务
 *
 * 负责订阅计划变更的按比例计费（proration）与执行。
 *
 * - calculateProration: 计算当前周期内中途变更的差价（按日比例）
 * - changePlan: 执行计划变更，支持 immediate（立即生效，重置计费周期）与 period_end（下周期生效，保留当前周期）
 * - getChangeHistory: 查询租户的订阅变更历史
 *
 * 计费周期默认按月（monthly），proration 计算使用 price_monthly 字段。
 *
 * 租户隔离通过显式 tenant_id 参数管理，支持管理端与 CLI 调用。
 */
class PlanChangeService
{
    use ResolvesPlan;

    /**
     * 计算按比例差价
     *
     * - immediate：按当前周期剩余天数计算差价
     *   proration = (new_daily_rate - old_daily_rate) * remaining_days
     * - period_end：下周期生效，无中途差价，proration_amount = 0
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $newPlanId  目标计划 ID
     * @param  string  $effectiveTiming  'immediate' 或 'period_end'
     * @return array{
     *   proration_amount: float,
     *   direction: 'charge'|'credit',
     *   effective_at: Carbon
     * }
     */
    public static function calculateProration(int $tenantId, int $newPlanId, string $effectiveTiming = 'immediate'): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $newPlan = SubscriptionPlan::findOrFail($newPlanId);

        return static::computeProrationResult($tenant, $newPlan, $effectiveTiming);
    }

    /**
     * 计算按比例差价（内部方法，接受已加载的模型实例）
     */
    protected static function computeProrationResult(Tenant $tenant, SubscriptionPlan $newPlan, string $effectiveTiming): array
    {
        $oldPlan = static::resolveCurrentPlan($tenant->tenant_id);

        $effectiveAt = $effectiveTiming === 'period_end' && $tenant->subscription_expires_at
            ? $tenant->subscription_expires_at
            : now();

        if ($effectiveTiming === 'period_end') {
            return [
                'proration_amount' => 0.0,
                'direction' => 'charge',
                'effective_at' => $effectiveAt,
            ];
        }

        $proration = static::computeProrationAmount($tenant, $oldPlan, $newPlan);
        $direction = $proration >= 0 ? 'charge' : 'credit';

        return [
            'proration_amount' => round(abs($proration), 2),
            'direction' => $direction,
            'effective_at' => $effectiveAt,
        ];
    }

    /**
     * 执行计划变更
     *
     * - immediate：立即生效，重置 subscription_started_at = now，subscription_expires_at = now + 1 月
     * - period_end：下周期生效，仅更新 subscription_plan_id，保留当前周期时间
     *
     * 记录到 subscription_histories 并返回新建的历史记录。
     */
    public static function changePlan(int $tenantId, int $newPlanId, string $effectiveTiming = 'immediate'): SubscriptionHistory
    {
        $tenant = Tenant::findOrFail($tenantId);
        $newPlan = SubscriptionPlan::findOrFail($newPlanId);

        if (! $newPlan->is_active) {
            throw new \RuntimeException('subscription.plan_not_available');
        }

        if ($tenant->status === 'suspended') {
            throw new \RuntimeException('subscription.tenant_suspended');
        }

        $oldPlan = static::resolveCurrentPlan($tenant->tenant_id);
        $prorationResult = static::computeProrationResult($tenant, $newPlan, $effectiveTiming);

        if ($oldPlan && $oldPlan->getKey() === $newPlan->getKey()) {
            throw new \RuntimeException('subscription.plan_unchanged');
        }

        $action = static::resolveAction($oldPlan, $newPlan);
        $now = now();

        return DB::transaction(function () use ($tenant, $newPlan, $oldPlan, $effectiveTiming, $prorationResult, $action, $now) {
            $fromPlanName = $oldPlan?->name;
            $toPlanName = $newPlan->name;

            $startsAt = $now->copy();
            $expiresAt = $now->copy()->addMonth();

            if ($effectiveTiming === 'period_end' && $tenant->subscription_expires_at) {
                $startsAt = $tenant->subscription_expires_at;
                $expiresAt = $tenant->subscription_expires_at->copy()->addMonth();
            }

            $history = SubscriptionHistory::create([
                'tenant_id' => $tenant->tenant_id,
                'plan_id' => $newPlan->getKey(),
                'action' => $action,
                'from_plan' => $fromPlanName,
                'to_plan' => $toPlanName,
                'billing_cycle' => 'monthly',
                'amount' => (float) $newPlan->price_monthly,
                'proration_amount' => $prorationResult['proration_amount'],
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'notes' => trans('multi_tenant_saas::subscription.plan_changed', ['from' => $fromPlanName, 'to' => $toPlanName]),
                'metadata' => [
                    'effective_timing' => $effectiveTiming,
                    'direction' => $prorationResult['direction'],
                    'old_plan_id' => $oldPlan?->getKey(),
                    'new_plan_id' => $newPlan->getKey(),
                ],
            ]);

            // period_end: plan_id 立即更新（新限制/功能立即生效），但计费周期不重置，
            // history.starts_at 记录为下周期开始时间，表示下周期起按新计划计费。
            $tenant->subscription_plan_id = $newPlan->getKey();
            $tenant->subscription_plan = $newPlan->name;

            if ($effectiveTiming === 'immediate') {
                $tenant->subscription_started_at = $now;
                $tenant->subscription_expires_at = $expiresAt;
            }

            $tenant->save();

            return $history;
        });
    }

    /**
     * 查询租户的变更历史
     *
     * @return Collection<int, SubscriptionHistory>
     */
    public static function getChangeHistory(int $tenantId): Collection
    {
        return SubscriptionHistory::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 计算按比例差价金额（带符号：正数为补差，负数为退差）
     */
    protected static function computeProrationAmount(Tenant $tenant, ?SubscriptionPlan $oldPlan, SubscriptionPlan $newPlan): float
    {
        if (! $oldPlan || $oldPlan->getKey() === $newPlan->getKey()) {
            return 0.0;
        }

        if (! $tenant->subscription_expires_at || $tenant->subscription_expires_at->isPast()) {
            return 0.0;
        }

        $now = now();
        $expiresAt = $tenant->subscription_expires_at;
        $remainingDays = $now->diffInDays($expiresAt);

        if ($remainingDays <= 0) {
            return 0.0;
        }

        $startedAt = $tenant->subscription_started_at ?? $now->copy()->subMonth();
        $totalDays = $startedAt->diffInDays($expiresAt);
        if ($totalDays <= 0) {
            $totalDays = 30;
        }

        $oldPrice = (float) ($oldPlan->price_monthly ?: 0);
        $newPrice = (float) ($newPlan->price_monthly ?: 0);

        $oldDailyRate = $oldPrice / $totalDays;
        $newDailyRate = $newPrice / $totalDays;

        return ($newDailyRate - $oldDailyRate) * $remainingDays;
    }

    /**
     * 根据价格比较解析变更动作
     */
    protected static function resolveAction(?SubscriptionPlan $oldPlan, SubscriptionPlan $newPlan): string
    {
        $oldPrice = (float) ($oldPlan?->price_monthly ?? 0);
        $newPrice = (float) $newPlan->price_monthly;

        if ($newPrice > $oldPrice) {
            return 'upgrade';
        }
        if ($newPrice < $oldPrice) {
            return 'downgrade';
        }

        return 'change';
    }
}
