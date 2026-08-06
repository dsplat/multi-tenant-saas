<?php

use Illuminate\Support\Facades\Route;

use MultiTenantSaas\Modules\Pay\Http\Controllers\SalesConfigController;

Route::prefix(config('pay.route_prefix', ''))->group(function () {

    // 销售折现配置（租户级）
    Route::get('sales-config', [SalesConfigController::class, 'show']);
    Route::put('sales-config', [SalesConfigController::class, 'update']);

});
