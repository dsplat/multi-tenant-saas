<?php

namespace MultiTenantSaas\Modules\Notification\Services;

use App\Notifications\CreditLowNotification;
use App\Notifications\GeneralNotification;
use App\Notifications\PaymentSuccessNotification;
use App\Notifications\SubscriptionExpiringNotification;
use App\Notifications\TenantSuspendedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Notification\Models\NotificationPreference;

class NotificationService
{
    public function __construct(private readonly TenantContextContract $tenantContext) {}

    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 根据通知偏好过滤用户集合
     */
    protected function filterByPreference(Collection $users, string $channel, ?string $type = null): Collection
    {
        return $users->filter(function (User $user) use ($channel, $type) {
            return NotificationPreference::isEnabled($user->id, $channel, $type);
        });
    }

    /**
     * 发送通用通知给指定用户
     */
    public function sendToUser(
        User $user,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        array $extra = []
    ): void {
        if (! NotificationPreference::isEnabled($user->id, 'database', 'general')) {
            return;
        }
        $user->notify(new GeneralNotification($title, $message, $type, $actionUrl, $extra));
    }

    /**
     * 批量发送通知给租户所有成员
     */
    public function sendToTenantUsers(
        int $tenantId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        array $extra = []
    ): void {
        $users = User::whereHas('tenants', function ($q) use ($tenantId) {
            $q->where('tenants.tenant_id', $tenantId)
                ->where('tenant_users.is_active', true);
        })->get();

        $users = $this->filterByPreference($users, 'database', 'general');

        if ($users->isNotEmpty()) {
            Notification::send($users, new GeneralNotification($title, $message, $type, $actionUrl, $extra));
        }
    }

    /**
     * 发送给租户管理员
     */
    public function sendToTenantAdmins(
        int $tenantId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        array $extra = []
    ): void {
        $tenantAdminRoleId = \DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        $users = User::whereHas('tenants', function ($q) use ($tenantId, $tenantAdminRoleId) {
            $q->where('tenants.tenant_id', $tenantId)
                ->where('tenant_users.is_active', true)
                ->where('tenant_users.role_id', $tenantAdminRoleId);
        })->get();

        $users = $this->filterByPreference($users, 'database', 'general');

        if ($users->isNotEmpty()) {
            Notification::send($users, new GeneralNotification($title, $message, $type, $actionUrl, $extra));
        }
    }

    /**
     * 通知租户暂停
     */
    public function notifyTenantSuspended(Tenant $tenant, ?string $reason = null): void
    {
        $users = User::whereHas('tenants', function ($q) use ($tenant) {
            $q->where('tenants.tenant_id', $tenant->tenant_id)
                ->where('tenant_users.is_active', true);
        })->get();

        $users = $this->filterByPreference($users, 'database', 'tenant_suspended');

        if ($users->isNotEmpty()) {
            Notification::send($users, new TenantSuspendedNotification($tenant->name, $reason));
        }
    }

    /**
     * 通知积分不足
     */
    public function notifyCreditLow(Tenant $tenant, int $remaining, int $threshold = 100): void
    {
        $tenantAdminRoleId = \DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        $admins = User::whereHas('tenants', function ($q) use ($tenant, $tenantAdminRoleId) {
            $q->where('tenants.tenant_id', $tenant->tenant_id)
                ->where('tenant_users.is_active', true)
                ->where('tenant_users.role_id', $tenantAdminRoleId);
        })->get();

        $admins = $this->filterByPreference($admins, 'database', 'credit_low');

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new CreditLowNotification($remaining, $threshold));
        }
    }

    /**
     * 通知订阅即将过期
     */
    public function notifySubscriptionExpiring(Tenant $tenant, int $daysLeft): void
    {
        $admins = User::whereHas('tenants', function ($q) use ($tenant) {
            $q->where('tenants.tenant_id', $tenant->tenant_id)
                ->wherePivot('is_active', true)
                ->wherePivotIn('role', ['tenant_admin']);
        })->get();

        $admins = $this->filterByPreference($admins, 'database', 'subscription_expiring');

        if ($admins->isNotEmpty()) {
            $planName = $tenant->subscription_plan ?? '免费版';
            $expiresAt = $tenant->subscription_expires_at?->format('Y-m-d H:i:s');

            Notification::send($admins, new SubscriptionExpiringNotification(
                $tenant->name,
                $planName,
                $expiresAt,
                $daysLeft
            ));
        }
    }

    /**
     * 通知支付成功
     */
    public function notifyPaymentSuccess(User $user, string $orderNo, int $amount, string $paymentMethod): void
    {
        if (! NotificationPreference::isEnabled($user->id, 'database', 'payment_success')) {
            return;
        }
        $user->notify(new PaymentSuccessNotification($orderNo, $amount, $paymentMethod));
    }

    /**
     * 获取用户未读通知数
     */
    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
