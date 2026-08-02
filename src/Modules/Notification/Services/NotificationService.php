<?php

namespace MultiTenantSaas\Modules\Notification\Services;

use App\Notifications\CreditLowNotification;
use App\Notifications\GeneralNotification;
use App\Notifications\PaymentSuccessNotification;
use App\Notifications\SubscriptionExpiringNotification;
use App\Notifications\TenantSuspendedNotification;
use Illuminate\Foundation\Auth\User as Authenticatable;
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
     * 根据通知偏好过滤用户集合
     */
    protected function filterByPreference(Collection $users, string $channel, ?string $type = null): Collection
    {
        return $users->filter(function (Authenticatable $user) use ($channel, $type) {
            return NotificationPreference::isEnabled($user->id, $channel, $type);
        });
    }

    /**
     * 解析租户管理员（Operator）绑定的 User ID 列表。
     *
     * 角色仅属 Operator：租户管理员经 operator_tenants（role/role_id）关联，
     * User 不拥有角色。通知以 Operator 绑定的 User 记录为对象。
     */
    protected function tenantAdminUserIds(int $tenantId): array
    {
        $tenantAdminRoleId = \DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        return \DB::table('operator_tenants')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->where(function ($q) use ($tenantAdminRoleId) {
                $q->where('role_id', $tenantAdminRoleId)
                    ->orWhere('role', 'tenant_admin');
            })
            ->pluck('user_id')
            ->all();
    }

    /**
     * 发送通用通知给指定用户
     */
    public function sendToUser(
        Authenticatable $user,
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
        $users = User::whereIn('user_id', $this->tenantAdminUserIds($tenantId))->get();

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
        $admins = User::whereIn('user_id', $this->tenantAdminUserIds($tenant->tenant_id))->get();

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
    public function notifyPaymentSuccess(Authenticatable $user, string $orderNo, int $amount, string $paymentMethod): void
    {
        if (! NotificationPreference::isEnabled($user->id, 'database', 'payment_success')) {
            return;
        }
        $user->notify(new PaymentSuccessNotification($orderNo, $amount, $paymentMethod));
    }

    /**
     * 获取用户未读通知数
     */
    public function getUnreadCount(Authenticatable $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
