<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\NotificationService;
use MultiTenantSaas\Services\AuditService;

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
        return response()->json(['unread_count' => $count]);
    }

    /**
     * 标记单条通知为已读
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json(['message' => '通知不存在'], 404);
        }

        $notification->markAsRead();

        return response()->json(['message' => '已标记为已读']);
    }

    /**
     * 批量标记为已读
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        AuditService::log('update', 'notification', null, '批量标记通知为已读');

        return response()->json(['message' => '全部已标记为已读']);
    }

    /**
     * 删除通知
     */
    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json(['message' => '通知不存在'], 404);
        }

        $notification->delete();

        return response()->json(['message' => '通知已删除']);
    }

    /**
     * 清空所有已读通知
     */
    public function clearRead(Request $request)
    {
        $request->user()->notifications()->whereNotNull('read_at')->delete();

        return response()->json(['message' => '已清空已读通知']);
    }
}
