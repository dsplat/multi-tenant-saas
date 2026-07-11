<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\SSL\Http\Controllers\TenantSslController;

Route::get('/tenants/{tenantId}/ssl', [TenantSslController::class, 'index']);
Route::post('/tenants/{tenantId}/ssl', [TenantSslController::class, 'store']);
Route::delete('/tenants/{tenantId}/ssl', [TenantSslController::class, 'destroy']);
