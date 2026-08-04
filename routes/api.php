<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Http\Controllers\AdminDashboardController;
use MultiTenantSaas\Http\Controllers\AdminMenuController;
use MultiTenantSaas\Http\Controllers\ChannelWebhookController;
use MultiTenantSaas\Http\Controllers\ConsoleDashboardController;
use MultiTenantSaas\Http\Controllers\ConsoleMenuController;
use MultiTenantSaas\Http\Controllers\McpClientController;
use MultiTenantSaas\Modules\Auth\Http\Controllers\TenantOAuthController;
use MultiTenantSaas\Modules\Event\Services\BroadcastingService;
use MultiTenantSaas\Modules\Notification\Http\Controllers\InAppNotificationController;
use MultiTenantSaas\Modules\Payment\Http\Controllers\TenantPaymentController;

// ========== 支付回调（无需认证，PayService 内验签） ==========
Route::post('/v1/pay/wechat/notify', [TenantPaymentController::class, 'wechatNotify']);
Route::post('/v1/pay/alipay/notify', [TenantPaymentController::class, 'alipayNotify']);
// 支付宝同步回跳 return_url 为浏览器 GET（参数同样带签），保留 GET 入口；微信 v3 回调仅 POST
Route::get('/v1/pay/alipay/notify', [TenantPaymentController::class, 'alipayNotify']);
Route::post('/v1/pay/wechat/refund-notify', [TenantPaymentController::class, 'wechatRefundNotify']);
Route::post('/v1/pay/alipay/refund-notify', [TenantPaymentController::class, 'alipayRefundNotify']);

// ========== 第三方登录回调（无需认证） ==========
Route::get('/v1/auth/{provider}/redirect', [TenantOAuthController::class, 'redirect']);
Route::get('/v1/auth/{provider}/callback', [TenantOAuthController::class, 'callback']);

// ========== 需要认证的 API ==========
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->group(function () {

    // 站内通知中心
    Route::get('/in-app-notifications', [InAppNotificationController::class, 'index']);
    Route::get('/in-app-notifications/categories', [InAppNotificationController::class, 'categories']);
    Route::get('/in-app-notifications/unread-count', [InAppNotificationController::class, 'unreadCount']);
    Route::post('/in-app-notifications/{id}/read', [InAppNotificationController::class, 'markAsRead']);
    Route::post('/in-app-notifications/read/batch', [InAppNotificationController::class, 'markBatchRead']);
    Route::post('/in-app-notifications/read-all', [InAppNotificationController::class, 'markAllRead']);
    Route::delete('/in-app-notifications/{id}', [InAppNotificationController::class, 'destroy']);
    Route::delete('/in-app-notifications/read/clear', [InAppNotificationController::class, 'clearRead']);
    Route::get('/in-app-notifications/preferences', [InAppNotificationController::class, 'getPreferences']);
    Route::post('/in-app-notifications/preferences', [InAppNotificationController::class, 'setPreference']);
    Route::post('/in-app-notifications/preferences/batch', [InAppNotificationController::class, 'batchSetPreferences']);

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

// ========== Channel Webhooks（无需认证，驱动内强制验签） ==========
// {type}：enterprise-wechat-app / enterprise-wechat-kf / wechat-official / slack ...
// {tenant_slug?}：可选租户标识（缺省回退 default_tenant_id）
Route::match(['get', 'post'], '/v1/channels/{type}/webhook/{tenant_slug?}', ChannelWebhookController::class)
    ->where('tenant_slug', '[A-Za-z0-9_-]+');

// ========== Admin 后台（菜单 + Dashboard） ==========
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1/admin')->group(function () {
    Route::get('/menu', [AdminMenuController::class, 'index']);
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);
});

// ========== Console 后台（菜单 + Dashboard） ==========
Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.identify'])->prefix('v1/console')->group(function () {
    Route::get('/menu', [ConsoleMenuController::class, 'index']);
    Route::get('/dashboard', [ConsoleDashboardController::class, 'index']);
});

// ========== MCP 客户端管理 ==========
Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.identify'])->prefix('v1')->group(function () {
    Route::get('/mcp-clients', [McpClientController::class, 'index']);
    Route::get('/mcp-clients/{id}', [McpClientController::class, 'show']);
    Route::post('/mcp-clients', [McpClientController::class, 'store']);
    Route::put('/mcp-clients/{id}', [McpClientController::class, 'update']);
    Route::delete('/mcp-clients/{id}', [McpClientController::class, 'destroy']);
});
