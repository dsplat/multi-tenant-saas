<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\BrandingConfigController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\ConsentController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\DataRetentionPolicyController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\FeatureFlagController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\IpWhitelistController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\ModuleController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\QueueController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\SystemSettingController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\TenantKeyController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\WebhookController;

// 系统级模块管理
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/admin/modules', [ModuleController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/admin/modules/{name}/enable', [ModuleController::class, 'enable']);
    Route::post('/admin/modules/{name}/disable', [ModuleController::class, 'disable']);
});

// 租户级模块管理
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tenants/{tenantId}/modules', [ModuleController::class, 'tenantIndex']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/tenants/{tenantId}/modules/{name}/enable', [ModuleController::class, 'tenantEnable']);
    Route::post('/tenants/{tenantId}/modules/{name}/disable', [ModuleController::class, 'tenantDisable']);
});

// Webhook 管理 (使用 setting 权限，因为 webhook 是系统配置)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tenants/{tenantId}/webhooks', [WebhookController::class, 'index']);
    Route::get('/tenants/{tenantId}/webhooks/{id}', [WebhookController::class, 'show']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/tenants/{tenantId}/webhooks', [WebhookController::class, 'store']);
    Route::put('/tenants/{tenantId}/webhooks/{id}', [WebhookController::class, 'update']);
    Route::delete('/tenants/{tenantId}/webhooks/{id}', [WebhookController::class, 'destroy']);
    Route::post('/tenants/{tenantId}/webhooks/{id}/test', [WebhookController::class, 'test']);
});

// IP 白名单管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tenants/{tenantId}/ip-whitelist', [IpWhitelistController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/tenants/{tenantId}/ip-whitelist', [IpWhitelistController::class, 'store']);
    Route::delete('/tenants/{tenantId}/ip-whitelist/{id}', [IpWhitelistController::class, 'destroy']);
});

// 功能开关管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/feature-flags', [FeatureFlagController::class, 'index']);
    Route::get('/feature-flags/{id}', [FeatureFlagController::class, 'show']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/feature-flags', [FeatureFlagController::class, 'store']);
    Route::put('/feature-flags/{id}', [FeatureFlagController::class, 'update']);
    Route::post('/feature-flags/{id}/toggle', [FeatureFlagController::class, 'toggle']);
});

// 品牌配置管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tenants/{tenantId}/branding', [BrandingConfigController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::put('/tenants/{tenantId}/branding', [BrandingConfigController::class, 'update']);
});

// 系统设置管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/admin/system-settings', [SystemSettingController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::put('/admin/system-settings/{group}', [SystemSettingController::class, 'update']);
});

// 租户密钥管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tenants/{tenantId}/tenant-keys', [TenantKeyController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/tenants/{tenantId}/tenant-keys', [TenantKeyController::class, 'store']);
    Route::delete('/tenants/{tenantId}/tenant-keys/{id}', [TenantKeyController::class, 'destroy']);
});

// 数据保留策略管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/admin/retention-policies', [DataRetentionPolicyController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/admin/retention-policies', [DataRetentionPolicyController::class, 'store']);
    Route::put('/admin/retention-policies/{id}', [DataRetentionPolicyController::class, 'update']);
    Route::delete('/admin/retention-policies/{id}', [DataRetentionPolicyController::class, 'destroy']);
});

// 同意管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/admin/consents', [ConsentController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/admin/consents', [ConsentController::class, 'store']);
    Route::post('/admin/consents/{id}/revoke', [ConsentController::class, 'revoke']);
});

// 队列失败任务管理 (使用 setting 权限)
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/admin/queue/failed', [QueueController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/admin/queue/failed/{id}/retry', [QueueController::class, 'retry']);
    Route::delete('/admin/queue/failed/{id}', [QueueController::class, 'destroy']);
    Route::post('/admin/queue/failed/retry-all', [QueueController::class, 'retryAll']);
    Route::delete('/admin/queue/failed', [QueueController::class, 'flush']);
});
