<?php

use MultiTenantSaas\Modules\Coupon\Http\Controllers\CouponController;

// ========== Coupon 优惠券 ==========
Route::prefix('/coupons')->group(function () {
    Route::get('/', [CouponController::class, 'index'])->middleware('rbac.permission:coupon.view');
    Route::post('/', [CouponController::class, 'store'])->middleware('rbac.permission:coupon.create');
    Route::get('/{couponId}', [CouponController::class, 'show'])->middleware('rbac.permission:coupon.view');
    Route::put('/{couponId}/activate', [CouponController::class, 'activate'])->middleware('rbac.permission:coupon.update');
    Route::put('/{couponId}/deactivate', [CouponController::class, 'deactivate'])->middleware('rbac.permission:coupon.update');
    Route::post('/redeem', [CouponController::class, 'redeem'])->middleware('rbac.permission:coupon.redeem');
    Route::post('/validate', [CouponController::class, 'validateCoupon']);
    Route::get('/{couponId}/usages', [CouponController::class, 'usages'])->middleware('rbac.permission:coupon.view');
    Route::get('/{couponId}/statistics', [CouponController::class, 'statistics'])->middleware('rbac.permission:coupon.view');
});

Route::prefix('/coupon-templates')->group(function () {
    Route::get('/', [CouponController::class, 'indexTemplates'])->middleware('rbac.permission:coupon.view');
    Route::post('/', [CouponController::class, 'storeTemplate'])->middleware('rbac.permission:coupon.create');
    Route::put('/{templateId}', [CouponController::class, 'updateTemplate'])->middleware('rbac.permission:coupon.update');
    Route::delete('/{templateId}', [CouponController::class, 'deleteTemplate'])->middleware('rbac.permission:coupon.delete');
    Route::put('/{templateId}/activate', [CouponController::class, 'activateTemplate'])->middleware('rbac.permission:coupon.update');
    Route::put('/{templateId}/deactivate', [CouponController::class, 'deactivateTemplate'])->middleware('rbac.permission:coupon.update');
    Route::post('/{templateId}/generate', [CouponController::class, 'generateFromTemplate'])->middleware('rbac.permission:coupon.create');
    Route::post('/{templateId}/distribute', [CouponController::class, 'bulkDistribute'])->middleware('rbac.permission:coupon.create');
    Route::post('/{templateId}/share', [CouponController::class, 'share']);
    Route::post('/accept-share', [CouponController::class, 'acceptShare']);
});

Route::prefix('/tenants/{tenantId}/coupon-shares')->group(function () {
    Route::get('/', [CouponController::class, 'shareRecords'])->middleware('rbac.permission:coupon.view');
});
