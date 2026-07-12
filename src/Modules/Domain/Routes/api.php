<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Domain\Http\Controllers\TenantDomainController;

Route::get('/tenants/{tenantId}/domain', [TenantDomainController::class, 'index']);
Route::put('/tenants/{tenantId}/domain', [TenantDomainController::class, 'update']);
Route::post('/tenants/{tenantId}/domain/approve', [TenantDomainController::class, 'approve']);
Route::post('/tenants/{tenantId}/domain/reject', [TenantDomainController::class, 'reject']);
