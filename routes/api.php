<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\TenantOAuthController;
use MultiTenantSaas\Modules\Event\Services\BroadcastingService;
use MultiTenantSaas\Modules\Notification\Services\InAppNotificationService;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;
use MultiTenantSaas\Services\Channel\ChannelManager;
use MultiTenantSaas\Services\Channel\MessageRouter;

// ========== 支付回调（无需认证） ==========
Route::post('/v1/pay/wechat/notify', [TenantPaymentController::class, 'wechatNotify']);
Route::post('/v1/pay/alipay/notify', [TenantPaymentController::class, 'alipayNotify']);
Route::get('/v1/pay/wechat/notify', [TenantPaymentController::class, 'wechatNotify']);
Route::get('/v1/pay/alipay/notify', [TenantPaymentController::class, 'alipayNotify']);
Route::post('/v1/pay/wechat/refund-notify', [TenantPaymentController::class, 'wechatRefundNotify']);
Route::post('/v1/pay/alipay/refund-notify', [TenantPaymentController::class, 'alipayRefundNotify']);

// ========== 第三方登录回调（无需认证） ==========
Route::get('/v1/auth/{provider}/redirect', [TenantOAuthController::class, 'redirect']);
Route::get('/v1/auth/{provider}/callback', [TenantOAuthController::class, 'callback']);

// ========== 需要认证的 API ==========
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->group(function () {

    // 站内通知中心
    Route::get('/in-app-notifications', function (Request $request) {
        $userId = (int) $request->user()->id;
        $service = app(InAppNotificationService::class);
        $result = $service->list($userId, [
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
                'unread_count' => $service->getUnreadCount($userId),
                'unread_by_type' => $service->getUnreadCountByType($userId),
            ],
        ]);
    });

    Route::get('/in-app-notifications/categories', function () {
        return response()->json([
            'success' => true,
            'data' => app(InAppNotificationService::class)->getCategories(),
        ]);
    });

    Route::get('/in-app-notifications/unread-count', function (Request $request) {
        $userId = (int) $request->user()->id;

        return response()->json([
            'success' => true,
            'unread_count' => app(InAppNotificationService::class)->getUnreadCount($userId),
            'unread_by_type' => app(InAppNotificationService::class)->getUnreadCountByType($userId),
        ]);
    });

    Route::post('/in-app-notifications/{id}/read', function (int $id) {
        $userId = (int) auth()->id();
        $ok = app(InAppNotificationService::class)->markAsRead($id, $userId);

        if (! $ok) {
            return response()->json(['success' => false, 'message' => trans('notification.not_found')], 404);
        }

        return response()->json(['success' => true, 'message' => trans('notification.marked_read')]);
    });

    Route::post('/in-app-notifications/read/batch', function (Request $request) {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);
        $count = app(InAppNotificationService::class)->markBatchRead($data['ids'], (int) auth()->id());

        return response()->json(['success' => true, 'marked_count' => $count]);
    });

    Route::post('/in-app-notifications/read-all', function () {
        $count = app(InAppNotificationService::class)->markAllRead((int) auth()->id());

        return response()->json(['success' => true, 'marked_count' => $count, 'message' => trans('notification.all_marked_read')]);
    });

    Route::delete('/in-app-notifications/{id}', function (int $id) {
        $ok = app(InAppNotificationService::class)->delete($id, (int) auth()->id());

        if (! $ok) {
            return response()->json(['success' => false, 'message' => trans('notification.not_found')], 404);
        }

        return response()->json(['success' => true, 'message' => trans('notification.deleted')]);
    });

    Route::delete('/in-app-notifications/read/clear', function () {
        $count = app(InAppNotificationService::class)->clearRead((int) auth()->id());

        return response()->json(['success' => true, 'cleared_count' => $count, 'message' => trans('notification.read_cleared')]);
    });

    Route::get('/in-app-notifications/preferences', function () {
        return response()->json([
            'success' => true,
            'data' => app(InAppNotificationService::class)->getPreferences((int) auth()->id()),
        ]);
    });

    Route::post('/in-app-notifications/preferences', function (Request $request) {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
        ]);
        app(InAppNotificationService::class)->setPreference(
            (int) auth()->id(),
            $data['channel'],
            $data['type'] ?? null,
            $data['enabled']
        );

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    });

    Route::post('/in-app-notifications/preferences/batch', function (Request $request) {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.channel' => ['required', 'string', 'max:30'],
            'preferences.*.type' => ['nullable', 'string', 'max:100'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);
        app(InAppNotificationService::class)->batchSetPreferences((int) auth()->id(), $data['preferences']);

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    });

    // 实时广播
    Route::get('/broadcast/history', function (Request $request) {
        $service = app(BroadcastingService::class);

        return response()->json([
            'success' => true,
            'data' => $service->getHistory(
                $request->query('event_type'),
                (int) $request->query('limit', 100)
            ),
        ]);
    })->middleware('rbac.permission:tenant.view');

    Route::get('/broadcast/status', function () {
        $service = app(BroadcastingService::class);

        return response()->json([
            'success' => true,
            'available' => $service->isAvailable(),
            'channel_prefix' => BroadcastingService::CHANNEL_PREFIX,
        ]);
    })->middleware('rbac.permission:tenant.view');

    Route::post('/broadcast/retry', function () {
        $count = app(BroadcastingService::class)->retryPending();

        return response()->json(['success' => true, 'retried_count' => $count]);
    })->middleware('rbac.permission:tenant.update');
});

// ========== Channel Webhooks（无需认证） ==========
Route::prefix('v1/channels')->group(function () {
    Route::post('/enterprise-wechat/webhook', function (Request $request) {
        $manager = app(ChannelManager::class);
        $provider = $manager->get('enterprise_wechat');
        $router = app(MessageRouter::class);

        if ($provider->verifyWebhook($request->all(), $request->headers->all())) {
            $router->routeMessage('enterprise_wechat', $request->all());

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false], 403);
    });
    Route::post('/wechat-official/webhook', function (Request $request) {
        $manager = app(ChannelManager::class);
        $provider = $manager->get('wechat_official');
        $router = app(MessageRouter::class);

        if ($provider->verifyWebhook($request->all(), $request->headers->all())) {
            $router->routeMessage('wechat_official', $request->all());

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false], 403);
    });
});
