<?php

namespace MultiTenantSaas\Modules\Notification\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Notification\Models\InAppNotification;
use MultiTenantSaas\Modules\Notification\Models\NotificationPreference;

/**
 * 站内通知服务
 *
 * 提供站内通知的完整生命周期管理：
 *  - 通知列表（分页、分类过滤、已读/未读过滤）
 *  - 已读/未读状态管理与批量标记已读
 *  - 通知分类（系统/账单/AI/安全）
 *  - 通知偏好（委托 NotificationPreference）
 *
 * 模型 InAppNotification 使用 BelongsToTenant，查询自动按当前租户上下文隔离。
 */
class InAppNotificationService
{
    /**
     * 创建站内通知
     *
     * @param  array{
     *   user_id: int,
     *   type?: string,
     *   title: string,
     *   body?: string|null,
     *   link?: string|null,
     *   metadata?: array|null
     * }  $data
     */
    public function create(array $data): InAppNotification
    {
        $type = $data['type'] ?? InAppNotification::TYPE_SYSTEM;

        if (! in_array($type, InAppNotification::TYPES, true)) {
            throw new \InvalidArgumentException(trans('notification.invalid_type'));
        }

        return InAppNotification::create([
            'user_id' => $data['user_id'],
            'type' => $type,
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * 分页查询当前用户站内通知
     *
     * @param  int  $userId  用户 ID
     * @param  array{type?: string, unread_only?: bool, per_page?: int}  $filters
     */
    public function list(int $userId, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = InAppNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('in_app_notification_id');

        if (! empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (! empty($filters['unread_only'])) {
            $query->unread();
        }

        return $query->paginate($perPage);
    }

    /**
     * 获取未读通知数
     */
    public function getUnreadCount(int $userId): int
    {
        return (int) InAppNotification::where('user_id', $userId)
            ->unread()
            ->count();
    }

    /**
     * 按分类统计未读数
     *
     * @return array<string,int>
     */
    public function getUnreadCountByType(int $userId): array
    {
        $rows = InAppNotification::where('user_id', $userId)
            ->unread()
            ->select('type', DB::raw('count(*) as cnt'))
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $result = [];
        foreach (InAppNotification::TYPES as $type) {
            $result[$type] = (int) ($rows->get($type)->cnt ?? 0);
        }

        return $result;
    }

    /**
     * 获取单条通知（校验归属用户）
     */
    public function find(int $notificationId, int $userId): ?InAppNotification
    {
        /** @var InAppNotification|null $notification */
        $notification = InAppNotification::where('in_app_notification_id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        return $notification;
    }

    /**
     * 标记单条通知为已读
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = $this->find($notificationId, $userId);

        if (! $notification) {
            return false;
        }

        if ($notification->is_read) {
            return true;
        }

        return $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * 批量标记指定 ID 的通知为已读
     *
     * @param  array<int>  $ids  通知 ID 列表
     */
    public function markBatchRead(array $ids, int $userId): int
    {
        return (int) InAppNotification::where('user_id', $userId)
            ->whereIn('in_app_notification_id', $ids)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * 标记当前用户全部未读通知为已读
     */
    public function markAllRead(int $userId): int
    {
        return (int) InAppNotification::where('user_id', $userId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * 删除单条通知（软删除，校验归属用户）
     */
    public function delete(int $notificationId, int $userId): bool
    {
        $notification = $this->find($notificationId, $userId);

        if (! $notification) {
            return false;
        }

        return (bool) $notification->delete();
    }

    /**
     * 清空当前用户已读通知
     */
    public function clearRead(int $userId): int
    {
        return (int) InAppNotification::where('user_id', $userId)
            ->read()
            ->delete();
    }

    /**
     * 获取通知分类列表
     *
     * @return array<string>
     */
    public function getCategories(): array
    {
        return InAppNotification::TYPES;
    }

    /**
     * 获取用户通知偏好
     *
     * @return array<string,mixed>
     */
    public function getPreferences(int $userId): array
    {
        return NotificationPreference::getUserPreferences($userId);
    }

    /**
     * 设置用户通知偏好
     */
    public function setPreference(int $userId, string $channel, ?string $type, bool $enabled): NotificationPreference
    {
        return NotificationPreference::setPreference($userId, $channel, $type, $enabled);
    }

    /**
     * 批量设置用户通知偏好
     *
     * @param  array<int,array{channel:string,type?:string|null,enabled:bool}>  $preferences
     */
    public function batchSetPreferences(int $userId, array $preferences): void
    {
        foreach ($preferences as $pref) {
            NotificationPreference::setPreference(
                $userId,
                $pref['channel'],
                $pref['type'] ?? null,
                $pref['enabled']
            );
        }
    }

    /**
     * 初始化用户默认通知偏好
     */
    public function initDefaultPreferences(int $userId): void
    {
        try {
            NotificationPreference::initDefaults($userId);
        } catch (\Throwable $e) {
            Log::warning('[InAppNotificationService] 初始化通知偏好失败', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取通知历史（用于审计/统计）
     */
    public function getHistory(int $userId, int $limit = 100): Collection
    {
        return InAppNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('in_app_notification_id')
            ->limit($limit)
            ->get();
    }
}
