<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Commerce\Http\Controllers\CommerceCatalogController;
use MultiTenantSaas\Modules\Commerce\Http\Controllers\CommerceOrderController;
use MultiTenantSaas\Modules\Commerce\Http\Controllers\CommerceSupplyGrantController;

// 商业体商品目录（console 端，租户运营浏览平台在售商品）
Route::prefix('commerce')->group(function () {
    Route::get('/skus', [CommerceCatalogController::class, 'index']);
    Route::get('/skus/{skuId}', [CommerceCatalogController::class, 'show']);

    // 订单（租户向平台购买）
    Route::post('/orders', [CommerceOrderController::class, 'store']);
    Route::get('/orders', [CommerceOrderController::class, 'index']);
    Route::get('/orders/{orderId}', [CommerceOrderController::class, 'show']);
    Route::post('/orders/{orderId}/pay', [CommerceOrderController::class, 'pay']);
    Route::post('/orders/{orderId}/cancel', [CommerceOrderController::class, 'cancel']);

    // 供给授权（租户已购供给「代理证」）
    Route::get('/supply-grants', [CommerceSupplyGrantController::class, 'index']);
    Route::get('/supply-grants/{grantId}', [CommerceSupplyGrantController::class, 'show']);
});
