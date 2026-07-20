<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\BrandingConfigController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\ConsentController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\DataRetentionPolicyController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\FeatureFlagController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\IpWhitelistController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\ModuleController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\SystemSettingController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\TenantKeyController;
use MultiTenantSaas\Modules\Infrastructure\Http\Controllers\WebhookController;

// 管理员后台 - 模块管理
Route::prefix('modules')->group(function () {
    Route::get('/', [ModuleController::class, 'index'])->middleware('rbac.permission:setting.view');
    Route::post('/{name}/enable', [ModuleController::class, 'enable'])->middleware('rbac.permission:setting.update');
    Route::post('/{name}/disable', [ModuleController::class, 'disable'])->middleware('rbac.permission:setting.update');
});

// Webhook 管理
Route::prefix('webhooks')->middleware('rbac.permission:webhook.view')->group(function () {
    Route::get('/', [WebhookController::class, 'index']);
    Route::get('/{id}', [WebhookController::class, 'show']);
});
Route::prefix('webhooks')->middleware('rbac.permission:webhook.update')->group(function () {
    Route::post('/', [WebhookController::class, 'store']);
    Route::put('/{id}', [WebhookController::class, 'update']);
    Route::delete('/{id}', [WebhookController::class, 'destroy']);
    Route::post('/{id}/test', [WebhookController::class, 'test']);
});

// IP 白名单管理
Route::prefix('ip-whitelist')->middleware('rbac.permission:security.view')->group(function () {
    Route::get('/', [IpWhitelistController::class, 'index']);
});
Route::prefix('ip-whitelist')->middleware('rbac.permission:security.update')->group(function () {
    Route::post('/', [IpWhitelistController::class, 'store']);
    Route::delete('/{id}', [IpWhitelistController::class, 'destroy']);
});

// 功能开关管理
Route::prefix('feature-flags')->middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/', [FeatureFlagController::class, 'index']);
    Route::get('/{id}', [FeatureFlagController::class, 'show']);
});
Route::prefix('feature-flags')->middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/', [FeatureFlagController::class, 'store']);
    Route::put('/{id}', [FeatureFlagController::class, 'update']);
    Route::post('/{id}/toggle', [FeatureFlagController::class, 'toggle']);
});

// 品牌配置管理
Route::prefix('branding')->middleware('rbac.permission:branding.view')->group(function () {
    Route::get('/', [BrandingConfigController::class, 'index']);
});
Route::prefix('branding')->middleware('rbac.permission:branding.update')->group(function () {
    Route::put('/', [BrandingConfigController::class, 'update']);
});

// 系统设置管理
Route::prefix('system-settings')->middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/', [SystemSettingController::class, 'index']);
});
Route::prefix('system-settings')->middleware('rbac.permission:setting.update')->group(function () {
    Route::put('/{group}', [SystemSettingController::class, 'update']);
});

// 租户密钥管理
Route::prefix('tenant-keys')->middleware('rbac.permission:security.view')->group(function () {
    Route::get('/', [TenantKeyController::class, 'index']);
});
Route::prefix('tenant-keys')->middleware('rbac.permission:security.update')->group(function () {
    Route::post('/', [TenantKeyController::class, 'store']);
    Route::delete('/{id}', [TenantKeyController::class, 'destroy']);
});

// 数据保留策略管理
Route::prefix('retention-policies')->middleware('rbac.permission:compliance.view')->group(function () {
    Route::get('/', [DataRetentionPolicyController::class, 'index']);
});
Route::prefix('retention-policies')->middleware('rbac.permission:compliance.update')->group(function () {
    Route::post('/', [DataRetentionPolicyController::class, 'store']);
    Route::put('/{id}', [DataRetentionPolicyController::class, 'update']);
    Route::delete('/{id}', [DataRetentionPolicyController::class, 'destroy']);
});

// 同意管理
Route::prefix('consents')->middleware('rbac.permission:compliance.view')->group(function () {
    Route::get('/', [ConsentController::class, 'index']);
});
Route::prefix('consents')->middleware('rbac.permission:compliance.update')->group(function () {
    Route::post('/', [ConsentController::class, 'store']);
    Route::post('/{id}/revoke', [ConsentController::class, 'revoke']);
});
