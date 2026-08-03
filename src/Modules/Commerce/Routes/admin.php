<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Commerce\Http\Controllers\Admin\CommerceAdminController;
use MultiTenantSaas\Modules\Commerce\Http\Controllers\Admin\CommerceContentAdminController;

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

    // 平台内容库（内容条目 + 内容包）
    Route::get('/content-library', [CommerceContentAdminController::class, 'contentIndex']);
    Route::post('/content-library', [CommerceContentAdminController::class, 'contentStore']);
    Route::put('/content-library/{contentId}', [CommerceContentAdminController::class, 'contentUpdate']);
    Route::post('/content-library/{contentId}/publish', [CommerceContentAdminController::class, 'contentPublish']);
    Route::delete('/content-library/{contentId}', [CommerceContentAdminController::class, 'contentRetire']);
    Route::get('/content-packs', [CommerceContentAdminController::class, 'packIndex']);
    Route::post('/content-packs', [CommerceContentAdminController::class, 'packStore']);
    Route::get('/content-packs/{packId}', [CommerceContentAdminController::class, 'packShow']);
    Route::put('/content-packs/{packId}', [CommerceContentAdminController::class, 'packUpdate']);
    Route::delete('/content-packs/{packId}', [CommerceContentAdminController::class, 'packRetire']);
});
