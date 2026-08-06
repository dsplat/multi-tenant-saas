<?php

use Illuminate\Support\Facades\Route;

use MultiTenantSaas\Modules\Order\Http\Controllers\OrderController;

// ========== 统一订单中心 ==========

Route::prefix(config('order.route_prefix', ''))->group(function () {

    // C 端：下单/支付/我的订单（具体路由前置，避免被 orders/{orderNo} 拦截）
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/pay-notify', [OrderController::class, 'payNotify']);
    Route::post('orders/{orderNo}/pay', [OrderController::class, 'pay']);
    Route::get('my/orders', [OrderController::class, 'myOrders']);

    // Console：订单列表/详情/退款
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{orderNo}', [OrderController::class, 'show']);
    Route::post('orders/{orderNo}/refund', [OrderController::class, 'refund']);

});
