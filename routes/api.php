<?php

use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AgentChatController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentStatsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RbacController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TenantAuditController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantCreditController;
use App\Http\Controllers\Api\TenantDomainController;
use App\Http\Controllers\Api\TenantMemberController;
use App\Http\Controllers\Api\TenantOAuthController;
use App\Http\Controllers\Api\TenantOnboardingController;
use App\Http\Controllers\Api\TenantPaymentController;
use App\Http\Controllers\Api\TenantQuotaController;
use App\Http\Controllers\Api\TenantSettingController;
use App\Http\Controllers\Api\TenantSslController;
use App\Http\Controllers\Api\TenantTokenController;
use App\Http\Controllers\Api\ToolController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\LotteryController;
use App\Http\Controllers\Api\VotingController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\CouponController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Http\Controllers\CapabilityController;
use MultiTenantSaas\Http\Controllers\ConversationController;
use MultiTenantSaas\Http\Controllers\WorkflowController;
use MultiTenantSaas\Services\BroadcastingService;
use MultiTenantSaas\Services\Channel\ChannelManager;
use MultiTenantSaas\Services\Channel\MessageRouter;
use MultiTenantSaas\Services\InAppNotificationService;

// ========== 认证 API（无需认证） ==========
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:5,1');
    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');
});

// ========== 租户引导注册 API（无需认证） ==========
Route::prefix('v1/tenants')->group(function () {
    Route::post('/register', [TenantOnboardingController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/onboarding/status', [TenantOnboardingController::class, 'status'])->middleware('throttle:10,1');
    Route::post('/onboarding/complete', [TenantOnboardingController::class, 'complete'])->middleware('throttle:5,1');
    Route::post('/onboarding/{step}', [TenantOnboardingController::class, 'saveStep'])->middleware('throttle:10,1');
});

// ========== 支付回调（无需认证，带 tenant_id 验签） ==========
Route::post('/v1/pay/wechat/notify', [TenantPaymentController::class, 'wechatNotify']);
Route::post('/v1/pay/alipay/notify', [TenantPaymentController::class, 'alipayNotify']);
Route::get('/v1/pay/wechat/notify', [TenantPaymentController::class, 'wechatNotify']);
Route::get('/v1/pay/alipay/notify', [TenantPaymentController::class, 'alipayNotify']);

// ========== 退款回调（无需认证，带 tenant_id 验签） ==========
Route::post('/v1/pay/wechat/refund-notify', [TenantPaymentController::class, 'wechatRefundNotify']);
Route::post('/v1/pay/alipay/refund-notify', [TenantPaymentController::class, 'alipayRefundNotify']);

// ========== 第三方登录回调（无需认证） ==========
Route::get('/v1/auth/{provider}/redirect', [TenantOAuthController::class, 'redirect']);
Route::get('/v1/auth/{provider}/callback', [TenantOAuthController::class, 'callback']);

// ========== SSO / SAML 集成（无需认证，回调在登录前） ==========
Route::get('/v1/sso/saml/metadata', [AuthController::class, 'samlMetadata']);
Route::get('/v1/sso/{provider}/redirect', [AuthController::class, 'ssoRedirect'])->middleware('throttle:10,1');
Route::get('/v1/sso/{provider}/callback', [AuthController::class, 'ssoCallback'])->middleware('throttle:10,1');
Route::post('/v1/sso/{provider}/callback', [AuthController::class, 'ssoCallback'])->middleware('throttle:10,1');

// ========== 文件分享下载（无需认证，签名验证） ==========
Route::get('/v1/files/{id}/share', [FileController::class, 'shareDownload']);

// ========== 需要认证的 API ==========
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->group(function () {

    // 认证
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/mfa/verify', [AuthController::class, 'mfaVerify'])->middleware('throttle:5,1');

    // 多因素认证与会话管理
    Route::prefix('/mfa')->group(function () {
        // TOTP 设备
        Route::post('/totp/setup', [MfaController::class, 'setupTotp']);
        Route::post('/totp/confirm', [MfaController::class, 'confirmTotp'])->middleware('throttle:5,1');
        // 邮箱/短信设备
        Route::post('/email/setup', [MfaController::class, 'setupEmail']);
        Route::post('/sms/setup', [MfaController::class, 'setupSms']);
        // 验证码发送
        Route::post('/email/send', [MfaController::class, 'sendEmailCode'])->middleware('throttle:3,1');
        Route::post('/sms/send', [MfaController::class, 'sendSmsCode'])->middleware('throttle:3,1');
        // 设备管理
        Route::get('/devices', [MfaController::class, 'devices']);
        Route::delete('/devices/{deviceId}', [MfaController::class, 'destroyDevice']);
        Route::put('/devices/{deviceId}', [MfaController::class, 'renameDevice']);
        Route::post('/devices/{deviceId}/primary', [MfaController::class, 'setPrimary']);
        // 恢复码
        Route::post('/recovery-codes/generate', [MfaController::class, 'generateRecoveryCodes'])->middleware('throttle:5,1');
        Route::get('/recovery-codes/status', [MfaController::class, 'recoveryCodeStatus']);
        // 会话管理
        Route::get('/sessions', [MfaController::class, 'sessions']);
        Route::delete('/sessions/{sessionId}', [MfaController::class, 'revokeSession']);
        Route::post('/sessions/revoke-all', [MfaController::class, 'revokeAllSessions']);
    });

    // 租户管理（需 tenant.view/create/update/delete/suspend 权限）
    Route::get('/tenants', [TenantController::class, 'index'])->middleware('rbac.permission:tenant.view');
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('rbac.permission:tenant.create');
    Route::get('/tenants/{tenantId}', [TenantController::class, 'show'])->middleware('rbac.permission:tenant.view');
    Route::put('/tenants/{tenantId}', [TenantController::class, 'update'])->middleware('rbac.permission:tenant.update');
    Route::delete('/tenants/{tenantId}', [TenantController::class, 'destroy'])->middleware('rbac.permission:tenant.delete');
    Route::post('/tenants/{tenantId}/suspend', [TenantController::class, 'suspend'])->middleware('rbac.permission:tenant.suspend');
    Route::post('/tenants/{tenantId}/activate', [TenantController::class, 'activate'])->middleware('rbac.permission:tenant.activate');

    // 成员管理（需 member.* 权限）
    Route::get('/tenants/{tenantId}/members', [TenantMemberController::class, 'index'])->middleware('rbac.permission:member.view');
    Route::post('/tenants/{tenantId}/members', [TenantMemberController::class, 'store'])->middleware('rbac.permission:member.create');
    Route::put('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'update'])->middleware('rbac.permission:member.update');
    Route::delete('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'destroy'])->middleware('rbac.permission:member.delete');

    // 积分管理
    Route::get('/tenants/{tenantId}/credits', [TenantCreditController::class, 'index'])->middleware('rbac.permission:credit.view');

    // 域名管理（需 domain.manage 权限）
    Route::get('/tenants/{tenantId}/domain', [TenantDomainController::class, 'index'])->middleware('rbac.permission:domain.manage');
    Route::put('/tenants/{tenantId}/domain', [TenantDomainController::class, 'update'])->middleware('rbac.permission:domain.manage');
    Route::post('/tenants/{tenantId}/domain/approve', [TenantDomainController::class, 'approve'])->middleware('rbac.permission:domain.manage');
    Route::post('/tenants/{tenantId}/domain/reject', [TenantDomainController::class, 'reject'])->middleware('rbac.permission:domain.manage');

    // SSL 证书（需 ssl.manage 权限）
    Route::get('/tenants/{tenantId}/ssl', [TenantSslController::class, 'index'])->middleware('rbac.permission:ssl.manage');
    Route::post('/tenants/{tenantId}/ssl', [TenantSslController::class, 'store'])->middleware('rbac.permission:ssl.manage');
    Route::delete('/tenants/{tenantId}/ssl', [TenantSslController::class, 'destroy'])->middleware('rbac.permission:ssl.manage');

    // 租户配置（需 setting.view/update 权限）
    Route::get('/tenants/{tenantId}/settings/{group?}', [TenantSettingController::class, 'index'])->middleware('rbac.permission:setting.view');
    Route::put('/tenants/{tenantId}/settings/{group}', [TenantSettingController::class, 'update'])->middleware('rbac.permission:setting.update');
    Route::post('/tenants/{tenantId}/settings/sms/test', [TenantSettingController::class, 'testSms'])->middleware('rbac.permission:setting.update');

    // 支付配置（需 payment.* 权限）
    Route::get('/tenants/{tenantId}/payment/config', [TenantPaymentController::class, 'getPaymentConfig'])->middleware('rbac.permission:payment.view');
    Route::put('/tenants/{tenantId}/payment/{driver}', [TenantPaymentController::class, 'updatePaymentConfig'])->middleware('rbac.permission:payment.create');

    // OAuth 配置（需 setting.update 权限）
    Route::get('/tenants/{tenantId}/oauth/config', [TenantOAuthController::class, 'getOAuthConfig'])->middleware('rbac.permission:setting.view');
    Route::put('/tenants/{tenantId}/oauth/{provider}', [TenantOAuthController::class, 'updateOAuthConfig'])->middleware('rbac.permission:setting.update');

    // SSO / SAML 提供方管理（需 setting.* 权限）
    Route::get('/tenants/{tenantId}/sso/providers', [AuthController::class, 'ssoProviders'])->middleware('rbac.permission:setting.view');
    Route::post('/tenants/{tenantId}/sso/providers', [AuthController::class, 'storeSsoProvider'])->middleware('rbac.permission:setting.update');
    Route::delete('/tenants/{tenantId}/sso/providers/{name}', [AuthController::class, 'destroySsoProvider'])->middleware('rbac.permission:setting.update');

    // 支付订单（需 payment.* 权限）
    Route::get('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'index'])->middleware('rbac.permission:payment.view');
    Route::post('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'store'])->middleware('rbac.permission:payment.create');
    Route::post('/tenants/{tenantId}/payment-orders/refund', [TenantPaymentController::class, 'refund'])->middleware('rbac.permission:payment.refund');
    Route::get('/tenants/{tenantId}/payment-orders/refund-status', [TenantPaymentController::class, 'refundStatus'])->middleware('rbac.permission:payment.view');

    // 审计日志（需 audit.view 权限）
    Route::get('/tenants/{tenantId}/audit-logs', [TenantAuditController::class, 'index'])->middleware('rbac.permission:audit.view');

    // API Token
    Route::get('/tenants/{tenantId}/api-tokens', [TenantTokenController::class, 'index'])->middleware('rbac.permission:member.view');
    Route::get('/tenants/{tenantId}/api-tokens/abilities', [TenantTokenController::class, 'abilities'])->middleware('rbac.permission:member.view');
    Route::post('/tenants/{tenantId}/api-tokens', [TenantTokenController::class, 'store'])->middleware('rbac.permission:member.update');
    Route::delete('/tenants/{tenantId}/api-tokens/{tokenId}', [TenantTokenController::class, 'destroy'])->middleware('rbac.permission:member.update');

    // 配额
    Route::get('/tenants/{tenantId}/quotas', [TenantQuotaController::class, 'index'])->middleware('rbac.permission:tenant.view');

    // 系统设置（仅 super_admin）
    Route::get('/admin/settings', [AdminSettingsController::class, 'index']);
    Route::put('/admin/settings/{group}', [AdminSettingsController::class, 'update']);

    // RBAC 权限管理（需 rbac.manage 权限）
    Route::get('/rbac/permissions', [RbacController::class, 'permissions'])->middleware('rbac.permission:rbac.manage');
    Route::get('/tenants/{tenantId}/roles', [RbacController::class, 'roles'])->middleware('rbac.permission:rbac.manage');
    Route::post('/tenants/{tenantId}/roles', [RbacController::class, 'storeRole'])->middleware('rbac.permission:rbac.manage');
    Route::put('/tenants/{tenantId}/roles/{roleId}/permissions', [RbacController::class, 'updateRolePermissions'])->middleware('rbac.permission:rbac.manage');
    Route::delete('/tenants/{tenantId}/roles/{roleId}', [RbacController::class, 'destroyRole'])->middleware('rbac.permission:rbac.manage');
    Route::post('/tenants/{tenantId}/members/{userId}/role', [RbacController::class, 'assignMemberRole'])->middleware('rbac.permission:rbac.manage');

    // 通知中心
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications/read/clear', [NotificationController::class, 'clearRead']);
    // 通知偏好
    Route::get('/notifications/preferences', [NotificationController::class, 'getPreferences']);
    Route::post('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
    Route::post('/notifications/preferences/batch', [NotificationController::class, 'batchUpdatePreferences']);

    // ========== 站内通知中心（InAppNotificationService） ==========
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

    // 站内通知偏好
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

    // ========== 实时广播（BroadcastingService） ==========
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

    // 订阅管理
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
    Route::get('/subscription/plans/{planId}', [SubscriptionController::class, 'showPlan']);
    Route::post('/subscription/plans', [SubscriptionController::class, 'storePlan'])->middleware('rbac.permission:subscription.manage');
    Route::put('/subscription/plans/{planId}', [SubscriptionController::class, 'updatePlan'])->middleware('rbac.permission:subscription.manage');
    Route::delete('/subscription/plans/{planId}', [SubscriptionController::class, 'destroyPlan'])->middleware('rbac.permission:subscription.manage');
    Route::get('/tenants/{tenantId}/subscription', [SubscriptionController::class, 'current']);
    Route::post('/tenants/{tenantId}/subscription/subscribe', [SubscriptionController::class, 'subscribe'])->middleware('rbac.permission:subscription.manage');
    Route::post('/tenants/{tenantId}/subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware('rbac.permission:subscription.manage');
    Route::post('/tenants/{tenantId}/subscription/change', [SubscriptionController::class, 'changePlan'])->middleware('rbac.permission:subscription.manage');
    Route::get('/tenants/{tenantId}/subscription/history', [SubscriptionController::class, 'history']);

    // 文件存储
    Route::get('/files', [FileController::class, 'index']);
    Route::post('/files', [FileController::class, 'store'])->middleware('rbac.permission:file.upload');
    Route::get('/files/usage', [FileController::class, 'usage']);
    Route::get('/files/{id}', [FileController::class, 'show']);
    Route::get('/files/{id}/preview', [FileController::class, 'preview']);
    Route::get('/files/{id}/download', [FileController::class, 'download']);
    Route::post('/files/{id}/share', [FileController::class, 'share']);
    Route::delete('/files/{id}', [FileController::class, 'destroy'])->middleware('rbac.permission:file.delete');

    // ========== Agent 管理（§6.1） ==========
    Route::get('/agents', [AgentController::class, 'index']);
    Route::get('/agents/templates', [AgentController::class, 'templates']);
    Route::post('/agents/templates/{templateId}/clone', [AgentController::class, 'cloneTemplate']);
    Route::get('/agents/{agentId}', [AgentController::class, 'show']);
    Route::post('/agents', [AgentController::class, 'store']);
    Route::put('/agents/{agentId}', [AgentController::class, 'update']);
    Route::delete('/agents/{agentId}', [AgentController::class, 'destroy']);
    Route::post('/agents/{agentId}/enable', [AgentController::class, 'enable']);
    Route::post('/agents/{agentId}/disable', [AgentController::class, 'disable']);
    Route::put('/agents/{agentId}/model-config', [AgentController::class, 'updateModelConfig']);
    Route::put('/agents/{agentId}/tools', [AgentController::class, 'updateTools']);
    Route::put('/agents/{agentId}/knowledge-bases', [AgentController::class, 'updateKnowledgeBases']);

    // ========== Agent 对话 + SSE 流式（§6.2） ==========
    Route::post('/agents/{agentId}/chat', [AgentChatController::class, 'startChat']);
    Route::post('/agents/{agentId}/chat/{conversationId}', [AgentChatController::class, 'sendMessage']);
    Route::get('/agents/{agentId}/conversations', [AgentChatController::class, 'conversations']);
    Route::get('/conversations/{conversationId}', [AgentChatController::class, 'showConversation']);
    Route::get('/conversations/{conversationId}/messages', [AgentChatController::class, 'messages']);
    Route::delete('/conversations/{conversationId}', [AgentChatController::class, 'deleteConversation']);

    // ========== Agent 监控（§6.3） ==========
    Route::get('/agents/{agentId}/stats', [AgentStatsController::class, 'stats']);
    Route::get('/agents/{agentId}/token-usage', [AgentStatsController::class, 'tokenUsage']);
    Route::get('/agents/{agentId}/cost', [AgentStatsController::class, 'cost']);
    Route::get('/agents/{agentId}/tool-logs', [AgentStatsController::class, 'toolLogs']);

    // ========== 工具管理（§6.4） ==========
    Route::get('/tools', [ToolController::class, 'index']);
    Route::get('/tools/{slug}', [ToolController::class, 'show']);
    Route::post('/tools', [ToolController::class, 'store']);
    Route::put('/tools/{slug}', [ToolController::class, 'update']);
    Route::delete('/tools/{slug}', [ToolController::class, 'destroy']);

    // ========== Conversation Center（v0.2.0） ==========
    Route::prefix('/conversations-center')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/', [ConversationController::class, 'store']);
        Route::get('/{conversationId}', [ConversationController::class, 'show']);
        Route::post('/{conversationId}/close', [ConversationController::class, 'close']);
        Route::post('/{conversationId}/archive', [ConversationController::class, 'archive']);
        Route::get('/{conversationId}/messages', [ConversationController::class, 'messages']);
        Route::post('/{conversationId}/messages', [ConversationController::class, 'sendMessage']);
        Route::post('/{conversationId}/participants', [ConversationController::class, 'addParticipant']);
        Route::delete('/participants/{participantId}', [ConversationController::class, 'removeParticipant']);
        Route::get('/{conversationId}/sessions', [ConversationController::class, 'sessions']);
        Route::post('/{conversationId}/sessions', [ConversationController::class, 'openSession']);
        Route::post('/sessions/{sessionId}/close', [ConversationController::class, 'closeSession']);
        Route::get('/{conversationId}/tags', [ConversationController::class, 'tags']);
        Route::post('/{conversationId}/tags', [ConversationController::class, 'addTag']);
        Route::post('/messages/{messageId}/reactions', [ConversationController::class, 'addReaction']);
        Route::delete('/messages/{messageId}/reactions/{emoji}', [ConversationController::class, 'removeReaction']);
        Route::post('/messages/{messageId}/read', [ConversationController::class, 'markAsRead']);
    });

    // ========== Workflow Engine（v0.2.0） ==========
    Route::prefix('/workflows')->group(function () {
        Route::get('/', [WorkflowController::class, 'index']);
        Route::post('/', [WorkflowController::class, 'store']);
        Route::get('/{workflowId}', [WorkflowController::class, 'show']);
        Route::put('/{workflowId}', [WorkflowController::class, 'update']);
        Route::delete('/{workflowId}', [WorkflowController::class, 'destroy']);
        Route::post('/{workflowId}/activate', [WorkflowController::class, 'activate']);
        Route::post('/{workflowId}/pause', [WorkflowController::class, 'pause']);
        Route::post('/{workflowId}/execute', [WorkflowController::class, 'execute']);
        Route::get('/{workflowId}/executions', [WorkflowController::class, 'executions']);
        Route::get('/executions/{executionId}', [WorkflowController::class, 'showExecution']);
        Route::post('/executions/{executionId}/confirm', [WorkflowController::class, 'resumeExecution']);
        Route::post('/executions/{executionId}/cancel', [WorkflowController::class, 'cancelExecution']);
    });

    // ========== AI Capability（v0.2.0） ==========
    Route::prefix('/capabilities')->group(function () {
        Route::get('/', [CapabilityController::class, 'index']);
        Route::post('/execute', [CapabilityController::class, 'execute']);
        Route::post('/batch', [CapabilityController::class, 'batch']);
    });

    // ========== SMS 管理（SmsService） ==========
    Route::prefix('/tenants/{tenantId}/sms')->group(function () {
        // 模板管理
        Route::get('/templates', [SmsController::class, 'indexTemplates'])->middleware('rbac.permission:setting.view');
        Route::post('/templates', [SmsController::class, 'storeTemplate'])->middleware('rbac.permission:setting.update');
        Route::get('/templates/{templateId}', [SmsController::class, 'showTemplate'])->middleware('rbac.permission:setting.view');
        Route::put('/templates/{templateId}', [SmsController::class, 'updateTemplate'])->middleware('rbac.permission:setting.update');
        Route::delete('/templates/{templateId}', [SmsController::class, 'destroyTemplate'])->middleware('rbac.permission:setting.update');
        Route::post('/templates/{templateId}/submit-approval', [SmsController::class, 'submitForApproval'])->middleware('rbac.permission:setting.update');
        Route::post('/templates/{templateId}/render', [SmsController::class, 'renderContent'])->middleware('rbac.permission:setting.view');
        // 批量发送
        Route::post('/batch-send', [SmsController::class, 'batchSend'])->middleware('rbac.permission:setting.update');
        Route::post('/scheduled-send', [SmsController::class, 'scheduledSend'])->middleware('rbac.permission:setting.update');
        Route::get('/batch-tasks/{taskId}', [SmsController::class, 'showBatchTask'])->middleware('rbac.permission:setting.view');
        Route::post('/batch-tasks/{taskId}/cancel', [SmsController::class, 'cancelBatchTask'])->middleware('rbac.permission:setting.update');
        // 到达率统计
        Route::get('/batch-tasks/{taskId}/delivery-stats', [SmsController::class, 'deliveryStats'])->middleware('rbac.permission:setting.view');
        Route::get('/overall-stats', [SmsController::class, 'overallStats'])->middleware('rbac.permission:setting.view');
        // 退订管理
        Route::get('/unsubscribes', [SmsController::class, 'indexUnsubscribes'])->middleware('rbac.permission:setting.view');
        Route::post('/unsubscribes', [SmsController::class, 'storeUnsubscribe'])->middleware('rbac.permission:setting.update');
        Route::post('/unsubscribes/check', [SmsController::class, 'checkUnsubscribed'])->middleware('rbac.permission:setting.view');
    });

    // ========== Coupon 优惠券（CouponService） ==========
    Route::prefix('/coupons')->group(function () {
        // 优惠券管理
        Route::get('/', [CouponController::class, 'index'])->middleware('rbac.permission:coupon.view');
        Route::post('/', [CouponController::class, 'store'])->middleware('rbac.permission:coupon.create');
        Route::get('/{couponId}', [CouponController::class, 'show'])->middleware('rbac.permission:coupon.view');
        Route::put('/{couponId}/activate', [CouponController::class, 'activate'])->middleware('rbac.permission:coupon.update');
        Route::put('/{couponId}/deactivate', [CouponController::class, 'deactivate'])->middleware('rbac.permission:coupon.update');
        // 核销与统计
        Route::post('/redeem', [CouponController::class, 'redeem'])->middleware('rbac.permission:coupon.redeem');
        Route::post('/validate', [CouponController::class, 'validateCoupon']);
        Route::get('/{couponId}/usages', [CouponController::class, 'usages'])->middleware('rbac.permission:coupon.view');
        Route::get('/{couponId}/statistics', [CouponController::class, 'statistics'])->middleware('rbac.permission:coupon.view');
    });

    // 优惠券模板
    Route::prefix('/coupon-templates')->group(function () {
        Route::get('/', [CouponController::class, 'indexTemplates'])->middleware('rbac.permission:coupon.view');
        Route::post('/', [CouponController::class, 'storeTemplate'])->middleware('rbac.permission:coupon.create');
        Route::put('/{templateId}', [CouponController::class, 'updateTemplate'])->middleware('rbac.permission:coupon.update');
        Route::delete('/{templateId}', [CouponController::class, 'deleteTemplate'])->middleware('rbac.permission:coupon.delete');
        Route::put('/{templateId}/activate', [CouponController::class, 'activateTemplate'])->middleware('rbac.permission:coupon.update');
        Route::put('/{templateId}/deactivate', [CouponController::class, 'deactivateTemplate'])->middleware('rbac.permission:coupon.update');
        // 批量发券
        Route::post('/{templateId}/generate', [CouponController::class, 'generateFromTemplate'])->middleware('rbac.permission:coupon.create');
        Route::post('/{templateId}/distribute', [CouponController::class, 'bulkDistribute'])->middleware('rbac.permission:coupon.create');
        // 裂变发券
        Route::post('/{templateId}/share', [CouponController::class, 'share']);
        Route::post('/accept-share', [CouponController::class, 'acceptShare']);
    });

    // 分享记录
    Route::prefix('/tenants/{tenantId}/coupon-shares')->group(function () {
        Route::get('/', [CouponController::class, 'shareRecords'])->middleware('rbac.permission:coupon.view');
    });

    // ========== Form 表单（FormBuilderService） ==========
    Route::prefix('/tenants/{tenantId}/forms')->group(function () {
        // 表单管理
        Route::get('/', [FormController::class, 'index'])->middleware('rbac.permission:form.view');
        Route::post('/', [FormController::class, 'store'])->middleware('rbac.permission:form.create');
        Route::get('/{formId}', [FormController::class, 'show'])->middleware('rbac.permission:form.view');
        Route::put('/{formId}', [FormController::class, 'update'])->middleware('rbac.permission:form.update');
        Route::delete('/{formId}', [FormController::class, 'destroy'])->middleware('rbac.permission:form.delete');
        // 提交记录
        Route::get('/{formId}/submissions', [FormController::class, 'submissions'])->middleware('rbac.permission:form.view');
        // 统计与导出
        Route::get('/{formId}/statistics', [FormController::class, 'statistics'])->middleware('rbac.permission:form.view');
        Route::get('/{formId}/export', [FormController::class, 'export'])->middleware('rbac.permission:form.export');
    });

    // 表单提交（公开接口，支持匿名，限流：每分钟10次）
    Route::prefix('v1/forms')->group(function () {
        Route::post('/{formId}/submit', [FormController::class, 'submit'])->middleware('throttle:10,1');
    });

    // ========== Voting 投票（VotingService） ==========
    Route::prefix('/tenants/{tenantId}/voting')->group(function () {
        // 投票活动管理
        Route::get('/', [VotingController::class, 'index'])->middleware('rbac.permission:voting.view');
        Route::post('/', [VotingController::class, 'store'])->middleware('rbac.permission:voting.create');
        Route::get('/{voteId}', [VotingController::class, 'show'])->middleware('rbac.permission:voting.view');
        Route::put('/{voteId}', [VotingController::class, 'update'])->middleware('rbac.permission:voting.update');
        Route::delete('/{voteId}', [VotingController::class, 'destroy'])->middleware('rbac.permission:voting.delete');
        // 投票执行（限流：每分钟5次）
        Route::post('/{voteId}/cast', [VotingController::class, 'castVote'])->middleware(['rbac.permission:voting.vote', 'throttle:5,1']);
        // 排行榜与统计
        Route::get('/{voteId}/ranking', [VotingController::class, 'ranking'])->middleware('rbac.permission:voting.view');
        Route::get('/{voteId}/statistics', [VotingController::class, 'statistics'])->middleware('rbac.permission:voting.view');
        Route::get('/{voteId}/records', [VotingController::class, 'records'])->middleware('rbac.permission:voting.view');
    });

    // ========== Lottery 抽奖（LotteryService） ==========
    Route::prefix('/tenants/{tenantId}/lottery')->group(function () {
        // 活动管理
        Route::get('/', [LotteryController::class, 'index'])->middleware('rbac.permission:lottery.view');
        Route::post('/', [LotteryController::class, 'store'])->middleware('rbac.permission:lottery.create');
        Route::get('/{activityId}', [LotteryController::class, 'show'])->middleware('rbac.permission:lottery.view');
        Route::put('/{activityId}', [LotteryController::class, 'update'])->middleware('rbac.permission:lottery.update');
        Route::delete('/{activityId}', [LotteryController::class, 'destroy'])->middleware('rbac.permission:lottery.delete');
        Route::put('/{activityId}/status', [LotteryController::class, 'updateStatus'])->middleware('rbac.permission:lottery.update');
        // 奖品管理
        Route::get('/{activityId}/prizes', [LotteryController::class, 'indexPrizes'])->middleware('rbac.permission:lottery.view');
        Route::post('/{activityId}/prizes', [LotteryController::class, 'storePrize'])->middleware('rbac.permission:lottery.create');
        Route::put('/{activityId}/prizes/{prizeId}', [LotteryController::class, 'updatePrize'])->middleware('rbac.permission:lottery.update');
        Route::delete('/{activityId}/prizes/{prizeId}', [LotteryController::class, 'destroyPrize'])->middleware('rbac.permission:lottery.delete');
        // 抽奖执行（限流：每分钟10次）
        Route::post('/{activityId}/draw', [LotteryController::class, 'draw'])->middleware(['rbac.permission:lottery.draw', 'throttle:10,1']);
        // 黑名单管理
        Route::get('/{activityId}/blacklist', [LotteryController::class, 'indexBlacklist'])->middleware('rbac.permission:lottery.view');
        Route::post('/{activityId}/blacklist', [LotteryController::class, 'storeBlacklist'])->middleware('rbac.permission:lottery.create');
        Route::delete('/{activityId}/blacklist', [LotteryController::class, 'destroyBlacklist'])->middleware('rbac.permission:lottery.delete');
        // 统计查询
        Route::get('/{activityId}/statistics', [LotteryController::class, 'statistics'])->middleware('rbac.permission:lottery.view');
        Route::get('/{activityId}/my-logs', [LotteryController::class, 'userDrawLogs'])->middleware('rbac.permission:lottery.view');
        Route::get('/{activityId}/win-logs', [LotteryController::class, 'winLogs'])->middleware('rbac.permission:lottery.view');
        // 数据导出
        Route::get('/{activityId}/export', [LotteryController::class, 'export'])->middleware('rbac.permission:lottery.view');
    });

    // ========== Channel Webhooks（v0.2.0，无需认证） ==========
});

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
