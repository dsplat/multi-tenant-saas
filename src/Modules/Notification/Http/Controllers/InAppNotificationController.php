<?php

namespace MultiTenantSaas\Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Notification\Services\InAppNotificationService;

/**
 * @OA\Tag(
 *     name="站内通知中心",
 *     description="站内信列表、已读管理、清理与偏好设置"
 * )
 */
class InAppNotificationController extends Controller
{
    public function __construct(
        protected InAppNotificationService $service,
    ) {}

    /**
     * 通知列表（分页 + 未读统计）
     */
    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $result = $this->service->list($userId, [
            'type' => $request->query('type'),
            'unread_only' => $request->boolean('unread_only'),
            'per_page' => (int) $request->input('per_page', 20),
        ]);

        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'unread_count' => $this->service->getUnreadCount($userId),
                'unread_by_type' => $this->service->getUnreadCountByType($userId),
            ],
        ]);
    }

    /**
     * 通知分类字典
     */
    public function categories()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getCategories(),
        ]);
    }

    /**
     * 未读数统计
     */
    public function unreadCount(Request $request)
    {
        $userId = (int) $request->user()->id;

        return response()->json([
            'success' => true,
            'unread_count' => $this->service->getUnreadCount($userId),
            'unread_by_type' => $this->service->getUnreadCountByType($userId),
        ]);
    }

    /**
     * 标记单条已读
     */
    public function markAsRead(Request $request, int $id)
    {
        $ok = $this->service->markAsRead($id, (int) $request->user()->id);

        if (! $ok) {
            return response()->json(['success' => false, 'message' => trans('notification.not_found')], 404);
        }

        return response()->json(['success' => true, 'message' => trans('notification.marked_read')]);
    }

    /**
     * 批量标记已读
     */
    public function markBatchRead(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);
        $count = $this->service->markBatchRead($data['ids'], (int) $request->user()->id);

        return response()->json(['success' => true, 'marked_count' => $count]);
    }

    /**
     * 全部标记已读
     */
    public function markAllRead(Request $request)
    {
        $count = $this->service->markAllRead((int) $request->user()->id);

        return response()->json(['success' => true, 'marked_count' => $count, 'message' => trans('notification.all_marked_read')]);
    }

    /**
     * 删除单条通知
     */
    public function destroy(Request $request, int $id)
    {
        $ok = $this->service->delete($id, (int) $request->user()->id);

        if (! $ok) {
            return response()->json(['success' => false, 'message' => trans('notification.not_found')], 404);
        }

        return response()->json(['success' => true, 'message' => trans('notification.deleted')]);
    }

    /**
     * 清空已读通知
     */
    public function clearRead(Request $request)
    {
        $count = $this->service->clearRead((int) $request->user()->id);

        return response()->json(['success' => true, 'cleared_count' => $count, 'message' => trans('notification.read_cleared')]);
    }

    /**
     * 获取通知偏好
     */
    public function getPreferences(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getPreferences((int) $request->user()->id),
        ]);
    }

    /**
     * 更新单条通知偏好
     */
    public function setPreference(Request $request)
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
        ]);
        $this->service->setPreference(
            (int) $request->user()->id,
            $data['channel'],
            $data['type'] ?? null,
            $data['enabled']
        );

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    /**
     * 批量更新通知偏好
     */
    public function batchSetPreferences(Request $request)
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.channel' => ['required', 'string', 'max:30'],
            'preferences.*.type' => ['nullable', 'string', 'max:100'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);
        $this->service->batchSetPreferences((int) $request->user()->id, $data['preferences']);

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }
}
