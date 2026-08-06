<?php

use Illuminate\Support\Facades\Route;

use MultiTenantSaas\Modules\Logistics\Http\Controllers\ShipmentController;

// ========== 物流（发货登记/跟踪） ==========

Route::prefix(config('logistics.route_prefix', ''))->group(function () {

    Route::get('shipments', [ShipmentController::class, 'index']);
    Route::post('shipments', [ShipmentController::class, 'store']);
    Route::get('shipments/by-order/{orderNo}', [ShipmentController::class, 'byOrder']);
    Route::get('shipments/{shipmentId}', [ShipmentController::class, 'show']);
    Route::post('shipments/{shipmentId}/ship', [ShipmentController::class, 'ship']);
    Route::post('shipments/{shipmentId}/deliver', [ShipmentController::class, 'deliver']);
    Route::post('shipments/{shipmentId}/cancel', [ShipmentController::class, 'cancel']);

});
