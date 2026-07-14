<?php

namespace MultiTenantSaas\Modules\Monitoring\Services;

use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Billing\Models\FinancialRecord;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionHistory;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Notification\Services\NotificationService;

/**
 * 试用期管理服务
 *
 * 职责：
 * - 试用期初始化与状态查询
 * - 试用期延长（管理员手动）
 * - 试用到期提醒（到期前 3 天/1 天/当天）
 * - 试用到期处理（转为付费订阅或暂停租户）
 */
class TrialService
{
    /**
     * 默认试用期天数
     */
    public const DEFAULT_TRIAL_DAYS = 14;

    /**
     * 试用到期提醒阈值（天）
     */
    public const REMINDER_THRESHOLDS = [3, 1, 0];

    /**
     * 开始试用
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $planId  订阅计划ID
     * @param  int|null  $trialDays  试用期天数，null 时取计划配置或默认值
     */
    public static function startTrial(int $tenantId, int $planId, ?int $trialDays = null): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);
        $plan = SubscriptionPlan::findOrFail($planId);

        if (! $plan->is_active) {
            throw new \RuntimeException(trans('subscription.plan_not_available'));
        }

        if (static::isInTrial($tenant)) {
            throw new \RuntimeException(trans('subscription.trial_already_active'));
        }

        $days = $trialDays ?? ($plan->hasTrial() ? $plan->trial_days : static::DEFAULT_TRIAL_DAYS);
        if ($days <= 0) {
            $days = static::DEFAULT_TRIAL_DAYS;
        }

        return DB::transaction(function () use ($tenant, $plan, $days) {
            $now = now();
            $trialEndsAt = $now->copy()->addDays($days);
            $fromPlan = $tenant->subscription_plan;

            $tenant->subscription_plan = $plan->name;
            $tenant->subscription_plan_id = $plan->id;
            $tenant->subscription_started_at = $now;
            $tenant->subscription_expires_at = $trialEndsAt;
            $tenant->trial_ends_at = $trialEndsAt;
            $tenant->trial_extended = false;
            $tenant->trial_notification_sent_at = null;
            $tenant->auto_renew = false;
            $tenant->save();

            SubscriptionHistory::record(
                $tenant->tenant_id, 'trial', $fromPlan, $plan->name, 'monthly',
                0, 0, $now->toDateTimeString(), $trialEndsAt->toDateTimeString(),
                trans('subscription.trial_started')
            );

            return $tenant;
        });
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
     * 获取试用状态
     *
     * @return array{in_trial: bool, trial_ends_at: Carbon|null, days_remaining: int, is_extended: bool, status: string}
     */
    public static function getTrialStatus(int $tenantId): array
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant || ! $tenant->trial_ends_at) {
            return [
                'in_trial' => false,
                'trial_ends_at' => null,
                'days_remaining' => 0,
                'is_extended' => (bool) ($tenant?->trial_extended ?? false),
                'status' => trans('subscription.trial_status_none'),
            ];
        }

        $inTrial = static::isInTrial($tenant);
        $daysRemaining = $inTrial ? (int) ceil(now()->diffInDays($tenant->trial_ends_at)) : 0;

        return [
            'in_trial' => $inTrial,
            'trial_ends_at' => $tenant->trial_ends_at,
            'days_remaining' => $daysRemaining,
            'is_extended' => (bool) $tenant->trial_extended,
            'status' => $inTrial ? trans('subscription.trial_status_active') : trans('subscription.trial_status_expired'),
        ];
    }

    /**
     * 延长试用期（管理员手动）
     *
     * 注意：若试用期已过期，延长将从当前时间重新开始计算天数，
     * 而非从原 trial_ends_at 延续。此行为允许管理员为过期租户提供二次试用机会。
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $days  延长天数
     * @param  string|null  $reason  延长原因
     */
    public static function extendTrial(int $tenantId, int $days, ?string $reason = null): Tenant
    {
        if ($days <= 0) {
            throw new \RuntimeException(trans('subscription.trial_extend_invalid_days'));
        }

        $tenant = Tenant::findOrFail($tenantId);

        if (! $tenant->trial_ends_at) {
            throw new \RuntimeException(trans('subscription.trial_not_in_trial'));
        }

        $base = static::isInTrial($tenant) ? $tenant->trial_ends_at : now();
        $newEndsAt = $base->copy()->addDays($days);
        $previousEndsAt = $tenant->trial_ends_at;

        return DB::transaction(function () use ($tenant, $days, $reason, $newEndsAt, $previousEndsAt) {
            $tenant->trial_ends_at = $newEndsAt;
            $tenant->subscription_expires_at = $newEndsAt;
            $tenant->trial_extended = true;
            $tenant->trial_notification_sent_at = null;
            $tenant->save();

            SubscriptionHistory::record(
                $tenant->tenant_id, 'trial', $tenant->subscription_plan, $tenant->subscription_plan, 'monthly',
                0, 0, $previousEndsAt?->toDateTimeString(), $newEndsAt->toDateTimeString(),
                trans('subscription.trial_extended_success', ['days' => $days]),
                ['reason' => $reason, 'extended_days' => $days, 'previous_ends_at' => $previousEndsAt?->toDateTimeString()]
            );

            return $tenant;
        });
    }

    /**
     * 处理即将到期的试用期（发送提醒通知）
     * 阈值：到期前 3 天、1 天、当天
     * 使用 trial_notification_sent_at 避免同一天重复发送
     */
    public function processExpiringTrials(): int
    {
        $count = 0;
        $today = now()->toDateString();

        foreach (static::REMINDER_THRESHOLDS as $days) {
            $start = $days === 0 ? now()->copy()->startOfDay() : now()->copy()->addDays($days)->startOfDay();
            $end = $days === 0 ? now()->copy()->endOfDay() : now()->copy()->addDays($days)->endOfDay();

            $tenants = Tenant::whereBetween('trial_ends_at', [$start, $end])
                ->where('status', 'active')
                ->whereNotNull('trial_ends_at')
                ->where(function ($q) use ($today) {
                    $q->whereNull('trial_notification_sent_at')
                        ->orWhereDate('trial_notification_sent_at', '<', $today);
                })
                ->chunk(100, function ($tenants) use ($days, &$count) {
                    foreach ($tenants as $tenant) {
                        $title = trans('subscription.trial_expiring_title');
                        $message = $days === 0
                            ? trans('subscription.trial_expiring_today')
                            : trans('subscription.trial_expiring_body', ['days' => $days]);

                        NotificationService::sendToTenantAdmins(
                            $tenant->tenant_id,
                            $title,
                            $message,
                            'warning',
                            url('/console/subscription')
                        );

                        $tenant->trial_notification_sent_at = now();
                        $tenant->save();
                        $count++;
                    }
                });
        }

        if ($count > 0) {
            Log::info(trans('subscription.trial_reminders_sent', ['count' => $count]));
        }

        return $count;
    }

    /**
     * 处理已到期的试用期
     * - 已设置自动续费：转为付费订阅
     * - 未设置自动续费：暂停租户
     */
    public function processExpiredTrials(): int
    {
        $count = 0;

        Tenant::where('trial_ends_at', '<', now())
            ->where('status', 'active')
            ->whereNotNull('trial_ends_at')
            ->chunk(100, function ($tenants) use (&$count) {
                foreach ($tenants as $tenant) {
                    if ($tenant->auto_renew) {
                        $this->convertToPaidSubscription($tenant);
                    } else {
                        $this->suspendOnTrialExpiry($tenant);
                    }
                    $count++;
                }
            });

        if ($count > 0) {
            Log::info(trans('subscription.trial_processed', ['count' => $count]));
        }

        return $count;
    }

    /**
     * 试用到期转为付费订阅
     */
    protected function convertToPaidSubscription(Tenant $tenant): void
    {
        $plan = SubscriptionService::getCurrentPlan($tenant->tenant_id);

        if (! $plan || $plan->isFree()) {
            $this->suspendOnTrialExpiry($tenant);

            return;
        }

        $financialRecord = null;

        try {
            DB::transaction(function () use ($tenant, $plan, &$financialRecord) {
                $orderNo = 'TRIAL-' . now()->format('Ymd') . '-' . str_pad($tenant->tenant_id, 6, '0', STR_PAD_LEFT);
                $amount = $plan->price_monthly;

                $financialRecord = FinancialRecord::create([
                    'tenant_id' => $tenant->tenant_id,
                    'type' => 'subscription',
                    'amount' => $amount,
                    'status' => 'completed',
                    'metadata' => [
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                        'order_no' => $orderNo,
                        'source' => 'trial_conversion',
                        'auto_renew' => true,
                    ],
                ]);

                $expiresAt = now()->copy()->addMonth();
                $fromPlan = $tenant->subscription_plan;

                $tenant->subscription_expires_at = $expiresAt;
                $tenant->trial_ends_at = null;
                $tenant->trial_extended = false;
                $tenant->trial_notification_sent_at = null;
                $tenant->auto_renew = true;
                $tenant->save();

                SubscriptionHistory::record(
                    $tenant->tenant_id, 'subscribe', $fromPlan, $plan->name, 'monthly',
                    $amount, 0, now()->toDateTimeString(), $expiresAt->toDateTimeString(),
                    trans('subscription.trial_converted_to_paid')
                );
            });

            NotificationService::sendToTenantAdmins(
                $tenant->tenant_id,
                trans('subscription.trial_converted_to_paid'),
                trans('subscription.trial_converted_to_paid'),
                'success',
                url('/console/subscription')
            );
        } catch (\Exception $e) {
            if ($financialRecord) {
                $financialRecord->update(['status' => 'failed']);
            }

            Log::error(trans('subscription.trial_conversion_failed'), [
                'tenant_id' => $tenant->tenant_id,
                'error' => $e->getMessage(),
            ]);

            $this->suspendOnTrialExpiry($tenant);
        }
    }

    /**
     * 试用到期未续费，暂停租户
     */
    protected function suspendOnTrialExpiry(Tenant $tenant): void
    {
        $fromPlan = $tenant->subscription_plan;
        $reason = trans('subscription.trial_suspended');

        DB::transaction(function () use ($tenant, $fromPlan, $reason) {
            $tenant->status = 'suspended';
            $tenant->trial_ends_at = null;
            $tenant->trial_extended = false;
            $tenant->trial_notification_sent_at = null;
            $tenant->auto_renew = false;
            $tenant->save();

            SubscriptionHistory::record(
                $tenant->tenant_id, 'downgrade', $fromPlan, $fromPlan, null,
                0, 0, now()->toDateTimeString(), null, $reason
            );
        });

        NotificationService::notifyTenantSuspended($tenant, $reason);
    }
}
