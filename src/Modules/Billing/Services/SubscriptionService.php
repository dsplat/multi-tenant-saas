<?php

namespace MultiTenantSaas\Modules\Billing\Services;

use Illuminate\Support\Facades\Cache;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Billing\Models\FinancialRecord;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionHistory;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Notification\Services\NotificationService;

class SubscriptionService
{
    /**
     * 订阅计划
     */
    public static function subscribe(int $tenantId, int $planId, string $billingCycle = 'monthly', bool $startTrial = false): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);
        $plan = SubscriptionPlan::findOrFail($planId);

        if (! $plan->is_active) {
            throw new \RuntimeException(trans('subscription.plan_not_available'));
        }

        $now = now();
        $fromPlan = $tenant->subscription_plan;
        $expiresAt = null;

        if ($startTrial && $plan->hasTrial()) {
            $tenant->subscription_plan = $plan->name;
            $tenant->subscription_plan_id = $plan->id;
            $tenant->subscription_started_at = $now;
            $tenant->trial_ends_at = $now->copy()->addDays($plan->trial_days);
            $tenant->subscription_expires_at = $tenant->trial_ends_at;
            $tenant->auto_renew = false;
            $expiresAt = $tenant->trial_ends_at;

            SubscriptionHistory::record(
                $tenant->tenant_id, 'trial', $fromPlan, $plan->name, $billingCycle,
                0, 0, $now, $expiresAt, '试用开始'
            );
        } else {
            $expiresAt = $billingCycle === 'yearly'
                ? $now->copy()->addYear()
                : $now->copy()->addMonth();

            $tenant->subscription_plan = $plan->name;
            $tenant->subscription_plan_id = $plan->id;
            $tenant->subscription_started_at = $now;
            $tenant->subscription_expires_at = $expiresAt;
            $tenant->trial_ends_at = null;
            $tenant->auto_renew = true;

            $amount = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

            SubscriptionHistory::record(
                $tenant->tenant_id, 'subscribe', $fromPlan, $plan->name, $billingCycle,
                $amount, 0, $now, $expiresAt, '订阅成功'
            );
        }

        $tenant->save();

        return $tenant;
    }

    /**
     * 取消订阅（到期后降级为免费版）
     */
    public static function cancel(int $tenantId): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);
        $fromPlan = $tenant->subscription_plan;
        $tenant->auto_renew = false;
        $tenant->save();

        SubscriptionHistory::record(
            $tenant->tenant_id, 'cancel', $fromPlan, $fromPlan, null,
            0, 0, null, $tenant->subscription_expires_at,
            '取消自动续费，到期后降级为免费版'
        );

        return $tenant;
    }

    /**
     * 变更计划（支持按比例计算退补金额）
     */
    public static function changePlan(int $tenantId, int $newPlanId, string $billingCycle = 'monthly'): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);
        $newPlan = SubscriptionPlan::findOrFail($newPlanId);
        $oldPlan = static::getCurrentPlan($tenantId);

        if (! $newPlan->is_active) {
            throw new \RuntimeException(trans('subscription.plan_not_available'));
        }

        // 计算按比例退补金额
        $proration = static::calculateProration($tenant, $oldPlan, $newPlan, $billingCycle);

        // 执行订阅变更
        $tenant = static::subscribe($tenantId, $newPlanId, $billingCycle, false);

        // 记录计划变更历史
        $action = $newPlan->price_monthly > ($oldPlan?->price_monthly ?? 0) ? 'upgrade' : 'downgrade';

        SubscriptionHistory::record(
            $tenant->tenant_id, $action,
            $oldPlan?->name, $newPlan->name, $billingCycle,
            $billingCycle === 'yearly' ? $newPlan->price_yearly : $newPlan->price_monthly,
            $proration,
            now(), $tenant->subscription_expires_at,
            "计划从 {$oldPlan?->name} 变更为 {$newPlan->name}",
            ['proration' => $proration]
        );

        return $tenant;
    }

    /**
     * 按比例计算退补金额
     * 场景：用户在当前计费周期中途变更计划
     * - 升级：需补差价 = (新计划日费用 - 旧计划日费用) × 剩余天数
     * - 降级：退差价 = (旧计划日费用 - 新计划日费用) × 剩余天数
     */
    public static function calculateProration(
        Tenant $tenant,
        ?SubscriptionPlan $oldPlan,
        SubscriptionPlan $newPlan,
        string $billingCycle = 'monthly'
    ): float {
        if (! $oldPlan || $oldPlan->id === $newPlan->id) {
            return 0;
        }

        // 如果当前没有有效订阅或已过期，无需按比例计算
        if (! $tenant->subscription_expires_at || $tenant->subscription_expires_at->isPast()) {
            return 0;
        }

        $now = now();
        $expiresAt = $tenant->subscription_expires_at;

        // 计算剩余天数
        $remainingDays = $now->diffInDays($expiresAt);
        if ($remainingDays <= 0) {
            return 0;
        }

        // 计算当前计费周期总天数
        $startedAt = $tenant->subscription_started_at ?? $now->copy()->subMonth();
        $totalDays = $startedAt->diffInDays($expiresAt);
        if ($totalDays <= 0) {
            $totalDays = 30;
        }

        // 日费用
        $oldPrice = $billingCycle === 'yearly' ? ($oldPlan->price_yearly ?: 0) : ($oldPlan->price_monthly ?: 0);
        $newPrice = $billingCycle === 'yearly' ? ($newPlan->price_yearly ?: 0) : ($newPlan->price_monthly ?: 0);

        $oldDailyRate = $oldPrice / $totalDays;
        $newDailyRate = $newPrice / $totalDays;

        // 按比例退补
        $proration = ($newDailyRate - $oldDailyRate) * $remainingDays;

        return round($proration, 2);
    }

    /**
     * 获取订阅历史
     */
    public static function getHistory(int $tenantId, int $perPage = 15)
    {
        return SubscriptionHistory::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * 开始试用
     */
    public static function startTrial(int $tenantId, int $planId): Tenant
    {
        return static::subscribe($tenantId, $planId, 'monthly', true);
    }

    /**
     * 获取租户当前计划
     *
     * 订阅计划为低频变更的热数据，按 planId/name 缓存以减少重复查询。
     */
    public static function getCurrentPlan(int $tenantId): ?SubscriptionPlan
    {
        $tenant = Tenant::find($tenantId);

        return $tenant ? static::resolvePlanFromTenant($tenant) : null;
    }

    /**
     * 直接由 Tenant 模型解析计划（避免批量场景下重复回查租户行造成 N+1）
     *
     * 订阅计划为低频变更的热数据，按 planId/name 缓存。
     */
    protected static function resolvePlanFromTenant(Tenant $tenant): ?SubscriptionPlan
    {
        $ttl = (int) config('cache.ttl.subscription_plan', 1800);

        if (! $tenant->subscription_plan_id) {
            if ($tenant->subscription_plan) {
                return Cache::remember(
                    "plan:name:{$tenant->subscription_plan}",
                    $ttl,
                    fn () => SubscriptionPlan::where('name', $tenant->subscription_plan)->first()
                );
            }

            return Cache::remember(
                'plan:name:free',
                $ttl,
                fn () => SubscriptionPlan::where('name', 'free')->first()
            );
        }

        return Cache::remember(
            "plan:{$tenant->subscription_plan_id}",
            $ttl,
            fn () => SubscriptionPlan::find($tenant->subscription_plan_id)
        );
    }

    /**
     * 判断是否在试用期内
     */
    public static function isInTrial(Tenant $tenant): bool
    {
        return $tenant->trial_ends_at !== null
            && $tenant->trial_ends_at->isFuture();
    }

    /**
     * 处理即将过期的订阅（发送通知）
     */
    public function processExpiringSubscriptions(): int
    {
        $count = 0;
        $thresholds = [7, 3, 1];

        foreach ($thresholds as $days) {
            $start = now()->copy()->addDays($days)->startOfDay();
            $end = now()->copy()->addDays($days)->endOfDay();

            $tenants = Tenant::whereBetween('subscription_expires_at', [$start, $end])
                ->where('status', 'active')
                ->whereNotNull('subscription_plan_id')
                ->get();

            foreach ($tenants as $tenant) {
                // 直接复用已加载的 Tenant 模型解析计划，避免逐租户回查造成 N+1
                $plan = static::resolvePlanFromTenant($tenant);
                if ($plan && ! $plan->isFree()) {
                    NotificationService::notifySubscriptionExpiring($tenant, $days);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 处理已过期的订阅（降级为免费版）
     */
    public function processExpiredSubscriptions(): int
    {
        $tenants = Tenant::where('subscription_expires_at', '<', now())
            ->where('status', 'active')
            ->whereNotNull('subscription_plan_id')
            ->get();

        $freePlan = SubscriptionPlan::where('name', 'free')->first();
        $count = 0;

        foreach ($tenants as $tenant) {
            if ($tenant->auto_renew) {
                $this->autoRenew($tenant);
            } else {
                $fromPlan = $tenant->subscription_plan;
                $tenant->subscription_plan = 'free';
                $tenant->subscription_plan_id = $freePlan?->id;
                $tenant->auto_renew = false;
                $tenant->trial_ends_at = null;
                $tenant->save();

                SubscriptionHistory::record(
                    $tenant->tenant_id, 'downgrade', $fromPlan, 'free', null,
                    0, 0, now(), null, '订阅过期，降级为免费版'
                );

                NotificationService::sendToTenantAdmins(
                    $tenant->tenant_id,
                    trans('notification.subscription_expiring_title'),
                    trans('subscription.expired_downgraded'),
                    'warning',
                    url('/console/subscription')
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * 自动续费
     */
    protected function autoRenew(Tenant $tenant): void
    {
        // 直接复用传入的 Tenant 模型，避免回查租户行
        $plan = static::resolvePlanFromTenant($tenant);

        if (! $plan || $plan->isFree()) {
            return;
        }

        try {
            $orderNo = 'SUB-' . date('Ymd') . '-' . str_pad($tenant->tenant_id, 6, '0', STR_PAD_LEFT);

            $record = FinancialRecord::create([
                'tenant_id' => $tenant->tenant_id,
                'type' => 'subscription',
                'amount' => $plan->price_monthly,
                'status' => 'pending',
                'metadata' => [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'order_no' => $orderNo,
                    'auto_renew' => true,
                ],
            ]);

            // TODO: 调用 PayService 发起自动扣款
            Log::info('自动续费订单已创建', [
                'tenant_id' => $tenant->tenant_id,
                'order_no' => $orderNo,
                'amount' => $plan->price_monthly,
            ]);

            // 续费成功，延长订阅期
            $tenant->subscription_expires_at = now()->copy()->addMonth();
            $tenant->save();

            SubscriptionHistory::record(
                $tenant->tenant_id, 'renew', $plan->name, $plan->name, 'monthly',
                $plan->price_monthly, 0, now(), $tenant->subscription_expires_at,
                '自动续费成功'
            );

        } catch (\Exception $e) {
            Log::error('自动续费失败', [
                'tenant_id' => $tenant->tenant_id,
                'error' => $e->getMessage(),
            ]);

            $fromPlan = $tenant->subscription_plan;
            $tenant->subscription_plan = 'free';
            $tenant->auto_renew = false;
            $tenant->save();

            SubscriptionHistory::record(
                $tenant->tenant_id, 'downgrade', $fromPlan, 'free', null,
                0, 0, now(), null, '自动续费失败，降级为免费版'
            );

            NotificationService::sendToTenantAdmins(
                $tenant->tenant_id,
                trans('subscription.auto_renew_failed'),
                trans('subscription.auto_renew_failed'),
                'error',
                url('/console/subscription')
            );
        }
    }
}
