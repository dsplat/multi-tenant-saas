<?php

use Illuminate\Support\Facades\Route;

use MultiTenantSaas\Modules\Product\Http\Controllers\PackageController;
use MultiTenantSaas\Modules\Product\Http\Controllers\ProductController;
use MultiTenantSaas\Modules\Product\Http\Controllers\SkuController;

Route::prefix(config('product.route_prefix', ''))->group(function () {

// 商品管理
Route::apiResource('products', ProductController::class);

// C 端商城（H5）：仅上架商品
Route::get('shop/products', [ProductController::class, 'shopList']);
Route::get('shop/products/{id}', [ProductController::class, 'shopDetail']);

// SKU 管理（挂在商品下）
Route::get('products/{id}/skus', [SkuController::class, 'index']);
Route::post('products/{id}/skus', [SkuController::class, 'store']);
Route::put('products/{id}/skus/{skuId}', [SkuController::class, 'update']);
Route::delete('products/{id}/skus/{skuId}', [SkuController::class, 'destroy']);
Route::post('products/{id}/publish', [ProductController::class, 'publish']);
Route::post('products/{id}/unpublish', [ProductController::class, 'unpublish']);
Route::put('products/{id}/price-strategy', [ProductController::class, 'setPriceStrategy']);
Route::put('products/{id}/media-assets', [ProductController::class, 'setMediaAssets']);
Route::get('product-categories', [ProductController::class, 'indexCategories']);
Route::post('product-categories', [ProductController::class, 'storeCategory']);
Route::put('product-categories/{id}', [ProductController::class, 'updateCategory']);
Route::delete('product-categories/{id}', [ProductController::class, 'destroyCategory']);

// Package 组合实体（type=package 的商品 + package_items 组成）
Route::post('packages', [PackageController::class, 'store']);
Route::get('packages/{id}', [PackageController::class, 'show']);
Route::put('packages/{id}', [PackageController::class, 'update']);
Route::delete('packages/{id}', [PackageController::class, 'destroy']);
Route::get('packages/{id}/items', [PackageController::class, 'indexItems']);
Route::post('packages/{id}/items', [PackageController::class, 'storeItem']);
Route::delete('packages/{id}/items/{itemId}', [PackageController::class, 'destroyItem']);

});
