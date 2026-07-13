<?php

namespace MultiTenantSaas\Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\Notification\Models\NotificationPreference;
use MultiTenantSaas\Modules\Notification\Services\NotificationService;

/**
 * @OA\Tag(
 *     name="通知中心",
 *     description="通知列表、已读管理和通知偏好设置"
 * )
 */
class NotificationController extends Controller
{
    /**
     * 获取当前用户的通知列表
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = (int) $request->input('per_page', 20);
        $unreadOnly = $request->boolean('unread_only', false);

        $query = $user->notifications()->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => NotificationService::getUnreadCount($user),
            ],
        ]);
    }

    /**
     * 获取未读通知数
     */
    public function unreadCount(Request $request)
    {
        $count = NotificationService::getUnreadCount($request->user());

        return response()->json(['success' => true, 'unread_count' => $count]);
    }

    /**
     * 标记单条通知为已读
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => trans('notification.not_found')], 404);
        }

        $notification->markAsRead();

        return response()->json(['success' => true, 'message' => trans('notification.marked_read')]);
    }

    /**
     * 批量标记为已读
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        AuditService::log('update', 'notification', null, null, ['action' => 'mark_all_read']);

        return response()->json(['success' => true, 'message' => trans('notification.all_marked_read')]);
    }

    /**
     * 删除通知
     */
    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => trans('notification.not_found')], 404);
        }

        $notification->delete();

        return response()->json(['success' => true, 'message' => trans('notification.deleted')]);
    }

    /**
     * 清空所有已读通知
     */
    public function clearRead(Request $request)
    {
        $request->user()->notifications()->whereNotNull('read_at')->delete();

        return response()->json(['success' => true, 'message' => trans('notification.read_cleared')]);
    }

    /**
     * 获取通知偏好设置
     */
    public function getPreferences(Request $request)
    {
        $userId = $request->user()->id;
        $preferences = NotificationPreference::getUserPreferences($userId);

        return response()->json([
            'success' => true,
            'data' => $preferences,
            'channels' => NotificationPreference::CHANNELS,
            'types' => NotificationPreference::TYPES,
        ]);
    }

    /**
     * 更新通知偏好设置
     */
    public function updatePreferences(Request $request)
    {
        $request->validate([
            'channel' => 'required|in:' . implode(',', NotificationPreference::CHANNELS),
            'type' => 'nullable|in:' . implode(',', NotificationPreference::TYPES),
            'enabled' => 'required|boolean',
        ]);

        $userId = $request->user()->id;
        NotificationPreference::setPreference(
            $userId,
            $request->channel,
            $request->type,
            $request->boolean('enabled')
        );

        AuditService::log('update', 'notification_preference', $userId, null, $request->only(['channel', 'type', 'enabled']));

        return response()->json([
            'success' => true,
            'message' => trans('common.updated'),
        ]);
    }

    /**
     * 批量更新通知偏好
     */
    public function batchUpdatePreferences(Request $request)
    {
        $request->validate([
            'preferences' => 'required|array',
            'preferences.*.channel' => 'required|in:' . implode(',', NotificationPreference::CHANNELS),
            'preferences.*.type' => 'nullable|in:' . implode(',', NotificationPreference::TYPES),
            'preferences.*.enabled' => 'required|boolean',
        ]);

        $userId = $request->user()->id;
        foreach ($request->preferences as $pref) {
            NotificationPreference::setPreference(
                $userId,
                $pref['channel'],
                $pref['type'] ?? null,
                $pref['enabled']
            );
        }

        return response()->json([
            'success' => true,
            'message' => trans('common.updated'),
        ]);
    }
}
