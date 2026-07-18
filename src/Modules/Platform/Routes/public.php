<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Platform\Http\Controllers\ApplyFieldConfigController;
use MultiTenantSaas\Modules\Platform\Http\Controllers\TenantApplicationController;

// 公开查询申请进度（无需认证）
Route::get('/public/apply/{code}', [TenantApplicationController::class, 'status']);

// 公开获取申请字段配置（供申请表单渲染）
Route::get('/public/apply-fields', [ApplyFieldConfigController::class, 'index']);

// 公开获取站点配置（公开页面渲染用）
Route::get('/public/site-config', function () {
    return response()->json([
        'success' => true,
        'data' => [
            'platform_name' => config('app.name', 'Multi-Tenant SaaS'),
            'logo' => config('platform.logo'),
            'registration_enabled' => true,
            'apply_enabled' => true,
            'footer_text' => config('platform.footer_text', '© ' . date('Y') . ' All rights reserved.'),
        ],
    ]);
});
