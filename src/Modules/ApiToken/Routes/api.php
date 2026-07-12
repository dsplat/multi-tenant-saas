<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\ApiToken\Http\Controllers\TenantTokenController;

Route::get('/tenants/{tenantId}/api-tokens', [TenantTokenController::class, 'index']);
Route::get('/tenants/{tenantId}/api-tokens/abilities', [TenantTokenController::class, 'abilities']);
Route::post('/tenants/{tenantId}/api-tokens', [TenantTokenController::class, 'store']);
Route::delete('/tenants/{tenantId}/api-tokens/{tokenId}', [TenantTokenController::class, 'destroy']);
