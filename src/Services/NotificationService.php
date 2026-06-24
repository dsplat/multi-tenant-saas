<?php

namespace MultiTenantSaas\Services;

use App\Notifications\GeneralNotification;
use App\Notifications\TenantSuspendedNotification;
use App\Notifications\CreditLowNotification;
use App\Notifications\SubscriptionExpiringNotification;
use App\Notifications\PaymentSuccessNotification;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\Tenant;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    /**
     * 发送通用通知给指定用户
     */
    public static function sendToUser(
        User $user,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        array $extra = []
    ): void {
        $user->notify(new GeneralNotification($title, $message, $type, $actionUrl, $extra));
    }

    /**
     * 批量发送通知给租户所有成员
     */
    public static function sendToTenantUsers(
        int $tenantId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        array $extra = []
    ): void {
        $users = User::whereHas('tenants', function ($q) use ($tenantId) {
            $q->where('tenants.tenant_id', $tenantId)
              ->wherePivot('is_active', true);
        })->get();

        Notification::send($users, new GeneralNotification($title, $message, $type, $actionUrl, $extra));
    }

    /**
     * 发送给租户管理员
     */
    public static function sendToTenantAdmins(
        int $tenantId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        array $extra = []
    ): void {
        $users = User::whereHas('tenants', function ($q) use ($tenantId) {
            $q->where('tenants.tenant_id', $tenantId)
              ->wherePivot('is_active', true)
              ->wherePivotIn('role', ['tenant_admin']);
        })->get();

        Notification::send($users, new GeneralNotification($title, $message, $type, $actionUrl, $extra));
    }

    /**
     * 通知租户暂停
     */
    public static function notifyTenantSuspended(Tenant $tenant, ?string $reason = null): void
    {
        $users = User::whereHas('tenants', function ($q) use ($tenant) {
            $q->where('tenants.tenant_id', $tenant->tenant_id)
              ->wherePivot('is_active', true);
        })->get();

        Notification::send($users, new TenantSuspendedNotification($tenant->name, $reason));
    }

    /**
     * 通知积分不足
     */
    public static function notifyCreditLow(Tenant $tenant, int $remaining, int $threshold = 100): void
    {
        $admins = User::whereHas('tenants', function ($q) use ($tenant) {
            $q->where('tenants.tenant_id', $tenant->tenant_id)
              ->wherePivot('is_active', true)
              ->wherePivotIn('role', ['tenant_admin']);
        })->get();

        Notification::send($admins, new CreditLowNotification($remaining, $threshold));
    }

    /**
     * 通知订阅即将过期
     */
    public static function notifySubscriptionExpiring(Tenant $tenant, int $daysLeft): void
    {
        $admins = User::whereHas('tenants', function ($q) use ($tenant) {
            $q->where('tenants.tenant_id', $tenant->tenant_id)
              ->wherePivot('is_active', true)
              ->wherePivotIn('role', ['tenant_admin']);
        })->get();

        $planName = $tenant->subscription_plan ?? '免费版';
        $expiresAt = $tenant->subscription_expires_at?->format('Y-m-d H:i:s');

        Notification::send($admins, new SubscriptionExpiringNotification(
            $tenant->name,
            $planName,
            $expiresAt,
            $daysLeft
        ));
    }

    /**
     * 通知支付成功
     */
    public static function notifyPaymentSuccess(User $user, string $orderNo, int $amount, string $paymentMethod): void
    {
        $user->notify(new PaymentSuccessNotification($orderNo, $amount, $paymentMethod));
    }

    /**
     * 获取用户未读通知数
     */
    public static function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
