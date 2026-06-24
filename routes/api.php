<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantMemberController;
use App\Http\Controllers\Api\TenantSettingController;
use App\Http\Controllers\Api\TenantDomainController;
use App\Http\Controllers\Api\TenantCreditController;
use App\Http\Controllers\Api\TenantAuditController;
use App\Http\Controllers\Api\TenantSslController;
use App\Http\Controllers\Api\TenantPaymentController;
use App\Http\Controllers\Api\TenantOAuthController;
use App\Http\Controllers\Api\TenantTokenController;
use App\Http\Controllers\Api\TenantQuotaController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\RbacController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\FileController;

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

// ========== 需要认证的 API ==========
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {

    // 认证
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // 租户管理（仅 super_admin）
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{tenantId}', [TenantController::class, 'show']);
    Route::put('/tenants/{tenantId}', [TenantController::class, 'update']);
    Route::delete('/tenants/{tenantId}', [TenantController::class, 'destroy']);
    Route::post('/tenants/{tenantId}/suspend', [TenantController::class, 'suspend']);
    Route::post('/tenants/{tenantId}/activate', [TenantController::class, 'activate']);

    // 成员管理
    Route::get('/tenants/{tenantId}/members', [TenantMemberController::class, 'index']);
    Route::post('/tenants/{tenantId}/members', [TenantMemberController::class, 'store']);
    Route::put('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'update']);
    Route::delete('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'destroy']);

    // 积分管理
    Route::get('/tenants/{tenantId}/credits', [TenantCreditController::class, 'index']);

    // 域名管理
    Route::get('/tenants/{tenantId}/domain', [TenantDomainController::class, 'index']);
    Route::put('/tenants/{tenantId}/domain', [TenantDomainController::class, 'update']);
    Route::post('/tenants/{tenantId}/domain/approve', [TenantDomainController::class, 'approve']);
    Route::post('/tenants/{tenantId}/domain/reject', [TenantDomainController::class, 'reject']);

    // SSL 证书
    Route::get('/tenants/{tenantId}/ssl', [TenantSslController::class, 'index']);
    Route::post('/tenants/{tenantId}/ssl', [TenantSslController::class, 'store']);
    Route::delete('/tenants/{tenantId}/ssl', [TenantSslController::class, 'destroy']);

    // 租户配置
    Route::get('/tenants/{tenantId}/settings/{group?}', [TenantSettingController::class, 'index']);
    Route::put('/tenants/{tenantId}/settings/{group}', [TenantSettingController::class, 'update']);
    Route::post('/tenants/{tenantId}/settings/sms/test', [TenantSettingController::class, 'testSms']);

    // 支付配置
    Route::get('/tenants/{tenantId}/payment/config', [TenantPaymentController::class, 'getPaymentConfig']);
    Route::put('/tenants/{tenantId}/payment/{driver}', [TenantPaymentController::class, 'updatePaymentConfig']);

    // OAuth 配置
    Route::get('/tenants/{tenantId}/oauth/config', [TenantOAuthController::class, 'getOAuthConfig']);
    Route::put('/tenants/{tenantId}/oauth/{provider}', [TenantOAuthController::class, 'updateOAuthConfig']);

    // 支付订单
    Route::get('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'index']);
    Route::post('/tenants/{tenantId}/payment-orders', [TenantPaymentController::class, 'store']);
    Route::post('/tenants/{tenantId}/payment-orders/refund', [TenantPaymentController::class, 'refund']);

    // 审计日志
    Route::get('/tenants/{tenantId}/audit-logs', [TenantAuditController::class, 'index']);

    // API Token
    Route::get('/tenants/{tenantId}/api-tokens', [TenantTokenController::class, 'index']);
    Route::post('/tenants/{tenantId}/api-tokens', [TenantTokenController::class, 'store']);
    Route::delete('/tenants/{tenantId}/api-tokens/{tokenId}', [TenantTokenController::class, 'destroy']);

    // 配额
    Route::get('/tenants/{tenantId}/quotas', [TenantQuotaController::class, 'index']);

    // 系统设置（仅 super_admin）
    Route::get('/admin/settings', [AdminSettingsController::class, 'index']);
    Route::put('/admin/settings/{group}', [AdminSettingsController::class, 'update']);

    // RBAC 权限管理
    Route::get('/rbac/permissions', [RbacController::class, 'permissions']);
    Route::get('/tenants/{tenantId}/roles', [RbacController::class, 'roles']);
    Route::post('/tenants/{tenantId}/roles', [RbacController::class, 'storeRole']);
    Route::put('/tenants/{tenantId}/roles/{roleId}/permissions', [RbacController::class, 'updateRolePermissions']);
    Route::delete('/tenants/{tenantId}/roles/{roleId}', [RbacController::class, 'destroyRole']);
    Route::post('/tenants/{tenantId}/members/{userId}/role', [RbacController::class, 'assignMemberRole']);

    // 通知中心
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications/read/clear', [NotificationController::class, 'clearRead']);

    // 订阅管理
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
    Route::get('/subscription/plans/{planId}', [SubscriptionController::class, 'showPlan']);
    Route::post('/subscription/plans', [SubscriptionController::class, 'storePlan']);
    Route::put('/subscription/plans/{planId}', [SubscriptionController::class, 'updatePlan']);
    Route::delete('/subscription/plans/{planId}', [SubscriptionController::class, 'destroyPlan']);
    Route::get('/tenants/{tenantId}/subscription', [SubscriptionController::class, 'current']);
    Route::post('/tenants/{tenantId}/subscription/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('/tenants/{tenantId}/subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('/tenants/{tenantId}/subscription/change', [SubscriptionController::class, 'changePlan']);

    // 文件存储
    Route::get('/files', [FileController::class, 'index']);
    Route::post('/files', [FileController::class, 'store']);
    Route::get('/files/usage', [FileController::class, 'usage']);
    Route::get('/files/{id}', [FileController::class, 'show']);
    Route::get('/files/{id}/download', [FileController::class, 'download']);
    Route::delete('/files/{id}', [FileController::class, 'destroy']);
});
