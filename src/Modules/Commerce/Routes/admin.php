<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Commerce\Http\Controllers\Admin\CommerceAdminController;

// 平台管理端 - 商业体（SKU 管理 + 订单总览 + 履约补偿）
Route::prefix('commerce')->group(function () {
    Route::get('/skus', [CommerceAdminController::class, 'skuIndex']);
    Route::post('/skus', [CommerceAdminController::class, 'skuStore']);
    Route::put('/skus/{skuId}', [CommerceAdminController::class, 'skuUpdate']);
    Route::delete('/skus/{skuId}', [CommerceAdminController::class, 'skuRetire']);

    Route::get('/orders', [CommerceAdminController::class, 'orderIndex']);
    Route::post('/retry', [CommerceAdminController::class, 'retry']);

    // 供给授权管理（总览 + 停供/恢复）
    Route::get('/supply-grants', [CommerceAdminController::class, 'grantIndex']);
    Route::post('/supply-grants/{grantId}/suspend', [CommerceAdminController::class, 'grantSuspend']);
    Route::post('/supply-grants/{grantId}/resume', [CommerceAdminController::class, 'grantResume']);
});
