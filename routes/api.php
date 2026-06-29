<?php

use App\Http\Controllers\Api\AdminSettingsController;
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
use App\Http\Controllers\Api\TenantPaymentController;
use App\Http\Controllers\Api\TenantQuotaController;
use App\Http\Controllers\Api\TenantSettingController;
use App\Http\Controllers\Api\TenantSslController;
use App\Http\Controllers\Api\TenantTokenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Services\DeveloperPortalService;
use MultiTenantSaas\Services\SandboxService;
use MultiTenantSaas\Services\WebhookService;

// ========== 认证 API（无需认证） ==========
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:5,1');
    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');
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

    // ========== Webhook 管理（需 webhook.manage 权限） ==========
    Route::get('/webhooks/events', function () {
        return response()->json([
            'success' => true,
            'data' => app(WebhookService::class)->getSupportedEvents(),
        ]);
    })->middleware('rbac.permission:webhook.manage');

    Route::get('/webhooks', function (Request $request) {
        $service = app(WebhookService::class);
        $eventType = $request->query('event');
        $webhooks = $service->listWebhooks($eventType);

        return response()->json(['success' => true, 'data' => $webhooks]);
    })->middleware('rbac.permission:webhook.manage');

    Route::post('/webhooks', function (Request $request) {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'events' => ['required', 'array'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
        $webhook = app(WebhookService::class)
            ->createWebhook($data['url'], $data['events'], $data['description'] ?? null, $data['is_active'] ?? true);

        return response()->json(['success' => true, 'data' => $webhook], 201);
    })->middleware('rbac.permission:webhook.manage');

    Route::get('/webhooks/{id}', function (int $id) {
        $webhook = app(WebhookService::class)->findWebhook($id);
        if (! $webhook) {
            return response()->json(['success' => false, 'message' => trans('common.webhook_not_found')], 404);
        }

        return response()->json(['success' => true, 'data' => $webhook]);
    })->middleware('rbac.permission:webhook.manage');

    Route::put('/webhooks/{id}', function (Request $request, int $id) {
        $data = $request->validate([
            'url' => ['sometimes', 'string', 'max:500'],
            'events' => ['sometimes', 'array'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $webhook = app(WebhookService::class)->updateWebhook($id, $data);
        if (! $webhook) {
            return response()->json(['success' => false, 'message' => trans('common.webhook_not_found')], 404);
        }

        return response()->json(['success' => true, 'data' => $webhook]);
    })->middleware('rbac.permission:webhook.manage');

    Route::delete('/webhooks/{id}', function (int $id) {
        $deleted = app(WebhookService::class)->deleteWebhook($id);
        if (! $deleted) {
            return response()->json(['success' => false, 'message' => trans('common.webhook_not_found')], 404);
        }

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    })->middleware('rbac.permission:webhook.manage');

    Route::post('/webhooks/{id}/activate', function (int $id) {
        $webhook = app(WebhookService::class)->activateWebhook($id);
        if (! $webhook) {
            return response()->json(['success' => false, 'message' => trans('common.webhook_not_found')], 404);
        }

        return response()->json(['success' => true, 'data' => $webhook]);
    })->middleware('rbac.permission:webhook.manage');

    Route::post('/webhooks/{id}/deactivate', function (int $id) {
        $webhook = app(WebhookService::class)->deactivateWebhook($id);
        if (! $webhook) {
            return response()->json(['success' => false, 'message' => trans('common.webhook_not_found')], 404);
        }

        return response()->json(['success' => true, 'data' => $webhook]);
    })->middleware('rbac.permission:webhook.manage');

    Route::post('/webhooks/{id}/regenerate-secret', function (int $id) {
        $webhook = app(WebhookService::class)->regenerateSecret($id);
        if (! $webhook) {
            return response()->json(['success' => false, 'message' => trans('common.webhook_not_found')], 404);
        }

        return response()->json(['success' => true, 'data' => $webhook]);
    })->middleware('rbac.permission:webhook.manage');

    Route::get('/webhooks/{id}/deliveries', function (Request $request, int $id) {
        $service = app(WebhookService::class);
        $status = $request->query('status');
        $deliveries = $service->getDeliveries($id, $status);

        return response()->json(['success' => true, 'data' => $deliveries]);
    })->middleware('rbac.permission:webhook.manage');

    Route::post('/webhooks/deliveries/{id}/resend', function (int $id) {
        $resend = app(WebhookService::class)->resend($id);
        if (! $resend) {
            return response()->json(['success' => false, 'message' => trans('common.webhook_delivery_not_found')], 404);
        }

        return response()->json(['success' => true, 'message' => trans('common.webhook_resent')]);
    })->middleware('rbac.permission:webhook.manage');

    // ========== 开发者门户（API Key 管理 / 使用统计 / 文档 / 沙箱） ==========
    Route::prefix('/developer')->group(function () {
        // API Key 管理
        Route::get('/api-keys', function () {
            $userId = (int) auth()->id();

            return response()->json([
                'success' => true,
                'data' => app(DeveloperPortalService::class)->listApiKeys($userId),
            ]);
        });

        Route::post('/api-keys', function (Request $request) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'abilities' => ['nullable', 'array'],
                'abilities.*' => ['string'],
            ]);
            try {
                $result = app(DeveloperPortalService::class)
                    ->createApiKey((int) auth()->id(), $data['name'], $data['abilities'] ?? ['*']);

                return response()->json(['success' => true, 'data' => $result], 201);
            } catch (InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        });

        Route::get('/api-keys/{id}', function (int $id) {
            $result = app(DeveloperPortalService::class)->findApiKey((int) auth()->id(), $id);
            if (! $result) {
                return response()->json(['success' => false, 'message' => trans('common.api_key_not_found')], 404);
            }

            return response()->json(['success' => true, 'data' => $result]);
        });

        Route::delete('/api-keys/{id}', function (int $id) {
            $revoked = app(DeveloperPortalService::class)
                ->revokeApiKey((int) auth()->id(), $id);
            if (! $revoked) {
                return response()->json(['success' => false, 'message' => trans('common.api_key_not_found')], 404);
            }

            return response()->json(['success' => true, 'message' => trans('common.api_key_revoked')]);
        });

        Route::put('/api-keys/{id}/scopes', function (Request $request, int $id) {
            $data = $request->validate([
                'abilities' => ['required', 'array'],
                'abilities.*' => ['string'],
            ]);
            try {
                $updated = app(DeveloperPortalService::class)
                    ->updateApiKeyScopes((int) auth()->id(), $id, $data['abilities']);
                if (! $updated) {
                    return response()->json(['success' => false, 'message' => trans('common.api_key_not_found')], 404);
                }

                return response()->json(['success' => true, 'message' => trans('common.updated')]);
            } catch (InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        });

        // API 使用统计
        Route::get('/api-usage', function (Request $request) {
            $tokenId = $request->query('api_key_id') ? (int) $request->query('api_key_id') : null;

            return response()->json([
                'success' => true,
                'data' => app(DeveloperPortalService::class)
                    ->getUsageStats((int) auth()->id(), $tokenId),
            ]);
        });

        // 文档
        Route::get('/docs', function (Request $request) {
            $category = $request->query('category');
            $service = app(DeveloperPortalService::class);
            if ($category) {
                return response()->json(['success' => true, 'data' => $service->getDocumentationByCategory($category)]);
            }

            return response()->json(['success' => true, 'data' => $service->getDocumentation()]);
        });

        Route::get('/docs/categories', function () {
            return response()->json([
                'success' => true,
                'data' => app(DeveloperPortalService::class)->getDocumentationCategories(),
            ]);
        });

        // 沙箱环境
        Route::post('/sandboxes', function () {
            return response()->json([
                'success' => true,
                'data' => app(SandboxService::class)->createSandbox((int) auth()->id()),
            ], 201);
        });

        Route::get('/sandboxes/{id}', function (int $id) {
            $sandbox = app(SandboxService::class)->findSandbox($id);
            if (! $sandbox) {
                return response()->json(['success' => false, 'message' => trans('common.sandbox_not_found')], 404);
            }
            if ($sandbox->developer_id !== (int) auth()->id()) {
                return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
            }

            return response()->json(['success' => true, 'data' => $sandbox]);
        });

        Route::delete('/sandboxes/{id}', function (int $id) {
            $sandbox = app(SandboxService::class)->findSandbox($id);
            if (! $sandbox) {
                return response()->json(['success' => false, 'message' => trans('common.sandbox_not_found')], 404);
            }
            if ($sandbox->developer_id !== (int) auth()->id()) {
                return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
            }

            $cleaned = app(SandboxService::class)->cleanup($id);
            if (! $cleaned) {
                return response()->json(['success' => false, 'message' => trans('common.sandbox_not_found')], 404);
            }

            return response()->json(['success' => true, 'message' => trans('common.sandbox_cleaned')]);
        });

        Route::post('/sandboxes/{id}/activate', function (int $id) {
            $sandbox = app(SandboxService::class)->findSandbox($id);
            if (! $sandbox) {
                return response()->json(['success' => false, 'message' => trans('common.sandbox_not_found')], 404);
            }
            if ($sandbox->developer_id !== (int) auth()->id()) {
                return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
            }

            try {
                app(SandboxService::class)->activateSandboxTenant($id);

                return response()->json(['success' => true, 'message' => trans('common.sandbox_activated')]);
            } catch (RuntimeException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }
        });
    });
});
