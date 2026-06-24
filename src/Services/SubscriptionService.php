<?php

namespace MultiTenantSaas\Services;

use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\FinancialRecord;
use MultiTenantSaas\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SubscriptionService
{
    /**
     * 订阅计划
     */
    public static function subscribe(int $tenantId, int $planId, string $billingCycle = 'monthly', bool $startTrial = false): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);
        $plan = SubscriptionPlan::findOrFail($planId);

        if (!$plan->is_active) {
            throw new \RuntimeException('该订阅计划不可用');
        }

        $now = now();

        if ($startTrial && $plan->hasTrial()) {
            $tenant->subscription_plan = $plan->name;
            $tenant->subscription_plan_id = $plan->id;
            $tenant->subscription_started_at = $now;
            $tenant->trial_ends_at = $now->copy()->addDays($plan->trial_days);
            $tenant->subscription_expires_at = $tenant->trial_ends_at;
            $tenant->auto_renew = false;
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
        $tenant->auto_renew = false;
        $tenant->save();

        return $tenant;
    }

    /**
     * 变更计划
     */
    public static function changePlan(int $tenantId, int $newPlanId, string $billingCycle = 'monthly'): Tenant
    {
        return static::subscribe($tenantId, $newPlanId, $billingCycle, false);
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
     */
    public static function getCurrentPlan(int $tenantId): ?SubscriptionPlan
    {
        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->subscription_plan_id) {
            // 回退到字符串匹配
            if ($tenant && $tenant->subscription_plan) {
                return SubscriptionPlan::where('name', $tenant->subscription_plan)->first();
            }
            return SubscriptionPlan::where('name', 'free')->first();
        }
        return $tenant->subscription_plan_id
            ? SubscriptionPlan::find($tenant->subscription_plan_id)
            : SubscriptionPlan::where('name', 'free')->first();
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
                $plan = static::getCurrentPlan($tenant->tenant_id);
                if ($plan && !$plan->isFree()) {
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
                // 自动续费逻辑（需对接支付）
                $this->autoRenew($tenant);
            } else {
                // 降级为免费版
                $tenant->subscription_plan = 'free';
                $tenant->subscription_plan_id = $freePlan?->id;
                $tenant->auto_renew = false;
                $tenant->trial_ends_at = null;
                $tenant->save();

                NotificationService::sendToTenantAdmins(
                    $tenant->tenant_id,
                    '订阅已过期',
                    "您的订阅已过期，已降级为免费版。如需恢复，请续费。",
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
        $plan = static::getCurrentPlan($tenant->tenant_id);

        if (!$plan || $plan->isFree()) {
            return;
        }

        try {
            // 创建续费订单
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
            // 这里仅创建订单记录，实际扣款逻辑需要对接支付网关

            Log::info("自动续费订单已创建", [
                'tenant_id' => $tenant->tenant_id,
                'order_no' => $orderNo,
                'amount' => $plan->price_monthly,
            ]);

        } catch (\Exception $e) {
            Log::error("自动续费失败", [
                'tenant_id' => $tenant->tenant_id,
                'error' => $e->getMessage(),
            ]);

            // 续费失败，降级
            $tenant->subscription_plan = 'free';
            $tenant->auto_renew = false;
            $tenant->save();

            NotificationService::sendToTenantAdmins(
                $tenant->tenant_id,
                '自动续费失败',
                "自动续费扣款失败，已降级为免费版。请手动续费。",
                'error',
                url('/console/subscription')
            );
        }
    }
}
