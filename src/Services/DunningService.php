<?php

namespace MultiTenantSaas\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\PaymentOrder;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Services\NotificationService;

/**
 * 催收与到期管理服务（Dunning）
 *
 * 处理支付失败后的重试调度、订阅到期提醒与租户暂停。
 *
 * - 重试策略：默认 3 次，间隔 1/3/7 天（可通过 config('tenancy.dunning') 配置）
 * - 重试状态记录在 PaymentOrder.extra JSON 中：retry_count / next_retry_at
 * - 到期提醒阈值：到期前 7/3/1 天
 * - 宽限期：默认 7 天，超过宽限期未恢复则暂停租户
 *
 * 租户隔离通过显式 tenant_id 参数管理，支持 CLI/cron 与管理端调用。
 */
class DunningService
{
    /**
     * 默认重试次数上限
     */
    public const DEFAULT_MAX_RETRIES = 3;

    /**
     * 默认重试间隔（天），索引对应当前已重试次数
     */
    public const DEFAULT_RETRY_INTERVALS = [1, 3, 7];

    /**
     * 默认宽限期（天）
     */
    public const DEFAULT_GRACE_PERIOD_DAYS = 7;

    /**
     * 默认到期提醒阈值（天）
     */
    public const DEFAULT_REMINDER_THRESHOLDS = [7, 3, 1];

    /**
     * 处理失败支付：按重试策略决定下一步动作
     *
     * 流程：
     * 1. 查找该租户最近一笔 status='failed' 的 PaymentOrder
     * 2. 无失败记录 → action='none'
     * 3. 已重试次数 >= max_retries → action='suspend'（调用方应继续调用 suspendTenant）
     * 4. 否则 → action='retry'，next_retry_at = now + intervals[retry_count] 天，
     *    并将本次调度写入 PaymentOrder.extra（retry_count 自增、next_retry_at 更新）
     *
     * @return array{action: 'retry'|'suspend'|'none', next_retry_at: Carbon|null}
     */
    public static function processFailedPayment(int $tenantId): array
    {
        return DB::transaction(function () use ($tenantId) {
            $failedOrder = PaymentOrder::where('tenant_id', $tenantId)
                ->where('status', 'failed')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (! $failedOrder) {
                return ['action' => 'none', 'next_retry_at' => null];
            }

            $maxRetries = static::getMaxRetries();
            $intervals = static::getRetryIntervals();
            $extra = is_array($failedOrder->extra) ? $failedOrder->extra : [];
            $retryCount = (int) ($extra['retry_count'] ?? 0);

            if ($retryCount >= $maxRetries) {
                return ['action' => 'suspend', 'next_retry_at' => null];
            }

            $intervalDays = $intervals[$retryCount] ?? end($intervals) ?: 1;
            $nextRetryAt = now()->addDays((int) $intervalDays);

            $extra['retry_count'] = $retryCount + 1;
            $extra['next_retry_at'] = $nextRetryAt->toDateTimeString();
            $extra['dunning_status'] = 'retrying';

            $failedOrder->extra = $extra;
            $failedOrder->save();

            return ['action' => 'retry', 'next_retry_at' => $nextRetryAt];
        });
    }

    /**
     * 发送订阅到期提醒
     *
     * 仅在到期前 7/3/1 天匹配时发送，避免重复打扰。
     * 已过期或无订阅到期时间的租户不处理。
     */
    public static function sendExpiryReminder(int $tenantId): void
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant || ! $tenant->subscription_expires_at) {
            return;
        }

        $expiresAt = $tenant->subscription_expires_at;

        if ($expiresAt->isPast()) {
            $settings = $tenant->settings ?? [];
            if (! empty($settings['dunning_sent_reminders'])) {
                unset($settings['dunning_sent_reminders']);
                $tenant->settings = $settings;
                $tenant->save();
            }

            return;
        }

        $daysLeft = max(0, (int) now()->diffInDays($expiresAt, false));

        $thresholds = static::getReminderThresholds();

        if (! in_array($daysLeft, $thresholds, true)) {
            return;
        }

        $sentReminders = $tenant->settings['dunning_sent_reminders'] ?? [];
        if (in_array($daysLeft, $sentReminders, true)) {
            return;
        }

        NotificationService::notifySubscriptionExpiring($tenant, $daysLeft);

        $settings = $tenant->settings ?? [];
        $settings['dunning_sent_reminders'] = array_values(array_unique(array_merge($sentReminders, [$daysLeft])));
        $tenant->settings = $settings;
        $tenant->save();

        Log::info('Dunning: subscription expiry reminder sent', [
            'tenant_id' => $tenantId,
            'days_left' => $daysLeft,
        ]);
    }

    /**
     * 暂停租户
     *
     * 超过宽限期仍未恢复支付时调用：更新 tenants.status='suspended'，
     * 记录审计日志，并通知租户管理员。
     */
    public static function suspendTenant(int $tenantId): void
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return;
        }

        if ($tenant->status === 'suspended') {
            return;
        }

        $previousStatus = $tenant->status;
        $reason = 'dunning: payment failed and grace period exceeded';

        DB::transaction(function () use ($tenant, $previousStatus, $reason) {
            $tenant->status = 'suspended';
            $tenant->auto_renew = false;
            $tenant->save();

            AuditService::log(
                action: 'tenant_suspended',
                resourceType: 'tenant',
                resourceId: (int) $tenant->tenant_id,
                oldValues: ['status' => $previousStatus],
                newValues: ['status' => 'suspended', 'reason' => $reason]
            );
        });

        NotificationService::notifyTenantSuspended($tenant, $reason);

        Log::info('Dunning: tenant suspended', [
            'tenant_id' => $tenantId,
            'previous_status' => $previousStatus,
        ]);
    }

    /**
     * 获取租户催收状态
     *
     * @return array{
     *   retry_count: int,
     *   max_retries: int,
     *   grace_period_days: int,
     *   next_retry_at: Carbon|null,
     *   status: 'none'|'retrying'|'suspended'|'active'
     * }
     */
    public static function getDunningStatus(int $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        $failedOrder = static::findLatestFailedOrder($tenantId);
        $extra = $failedOrder && is_array($failedOrder->extra) ? $failedOrder->extra : [];

        $retryCount = (int) ($extra['retry_count'] ?? 0);
        $nextRetryAt = isset($extra['next_retry_at'])
            ? Carbon::parse($extra['next_retry_at'])
            : null;

        if ($tenant && $tenant->status === 'suspended') {
            $status = 'suspended';
        } elseif ($failedOrder) {
            $status = 'retrying';
        } else {
            $status = $tenant && $tenant->status === 'active' ? 'active' : 'none';
        }

        return [
            'retry_count' => $retryCount,
            'max_retries' => static::getMaxRetries(),
            'grace_period_days' => static::getGracePeriodDays(),
            'next_retry_at' => $nextRetryAt,
            'status' => $status,
        ];
    }

    /**
     * 查找租户最近一笔失败支付订单
     */
    protected static function findLatestFailedOrder(int $tenantId): ?PaymentOrder
    {
        return PaymentOrder::where('tenant_id', $tenantId)
            ->where('status', 'failed')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * 读取最大重试次数
     */
    protected static function getMaxRetries(): int
    {
        return (int) config('tenancy.dunning.max_retries', static::DEFAULT_MAX_RETRIES);
    }

    /**
     * 读取重试间隔（天）数组
     */
    protected static function getRetryIntervals(): array
    {
        $intervals = config('tenancy.dunning.retry_intervals', static::DEFAULT_RETRY_INTERVALS);

        return is_array($intervals) ? array_map('intval', $intervals) : static::DEFAULT_RETRY_INTERVALS;
    }

    /**
     * 读取宽限期（天）
     */
    protected static function getGracePeriodDays(): int
    {
        return (int) config('tenancy.dunning.grace_period_days', static::DEFAULT_GRACE_PERIOD_DAYS);
    }

    /**
     * 读取到期提醒阈值（天）
     */
    protected static function getReminderThresholds(): array
    {
        $thresholds = config('tenancy.dunning.reminder_thresholds', static::DEFAULT_REMINDER_THRESHOLDS);

        return is_array($thresholds) ? array_map('intval', $thresholds) : static::DEFAULT_REMINDER_THRESHOLDS;
    }
}
