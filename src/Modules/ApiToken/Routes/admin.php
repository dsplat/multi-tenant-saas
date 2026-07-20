<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\ApiToken\Http\Controllers\TenantTokenController;

Route::prefix('api-tokens')->group(function () {
    Route::get('/', [TenantTokenController::class, 'adminIndex']);
    Route::delete('/{tenantId}/{tokenId}', [TenantTokenController::class, 'adminDestroy']);
});
