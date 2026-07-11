<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Domain\Http\Controllers\TenantDomainController;

Route::prefix('admin/domains')->group(function () {
    Route::get('/', [TenantDomainController::class, 'index']);
    Route::post('/{tenantId}', [TenantDomainController::class, 'store']);
    Route::put('/{tenantId}', [TenantDomainController::class, 'update']);
    Route::delete('/{tenantId}', [TenantDomainController::class, 'destroy']);
    Route::post('/{tenantId}/approve', [TenantDomainController::class, 'approve']);
    Route::post('/{tenantId}/reject', [TenantDomainController::class, 'reject']);
});
