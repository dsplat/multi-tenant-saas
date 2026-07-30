<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Ibot\Http\Controllers\IbotAdminController;

// 租户后台 - ibot 频道配置管理（与 OAuth/邮件配置同级权限）
Route::prefix('tenant/ibot')->middleware('rbac.permission:setting.update')->group(function () {
    Route::get('/ibots', [IbotAdminController::class, 'index']);
    Route::post('/ibots', [IbotAdminController::class, 'store']);
    Route::put('/ibots/{ibotId}', [IbotAdminController::class, 'update'])->whereNumber('ibotId');
    Route::put('/ibots/{ibotId}/status', [IbotAdminController::class, 'updateStatus'])->whereNumber('ibotId');
    Route::delete('/ibots/{ibotId}', [IbotAdminController::class, 'destroy'])->whereNumber('ibotId');
});
