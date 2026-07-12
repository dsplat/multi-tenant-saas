<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\ApiToken\Http\Controllers\TenantTokenController;

Route::prefix('tenant/api-tokens')->group(function () {
    Route::get('/', [TenantTokenController::class, 'index']);
    Route::post('/', [TenantTokenController::class, 'store']);
    Route::delete('/{tokenId}', [TenantTokenController::class, 'destroy']);
    Route::get('/abilities', [TenantTokenController::class, 'abilities']);
});
