<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Domain\Http\Controllers\ReservedDomainController;
use MultiTenantSaas\Modules\Domain\Http\Controllers\TenantDomainController;

Route::prefix('domains')->group(function () {
    Route::get('/', [TenantDomainController::class, 'index'])->middleware('rbac.permission:domain.manage');
    Route::post('/{tenantId}', [TenantDomainController::class, 'store'])->middleware('rbac.permission:domain.manage');
    Route::put('/{tenantId}', [TenantDomainController::class, 'update'])->middleware('rbac.permission:domain.manage');
    Route::delete('/{tenantId}', [TenantDomainController::class, 'destroy'])->middleware('rbac.permission:domain.manage');
    Route::post('/{tenantId}/approve', [TenantDomainController::class, 'approve'])->middleware('rbac.permission:domain.manage');
    Route::post('/{tenantId}/reject', [TenantDomainController::class, 'reject'])->middleware('rbac.permission:domain.manage');
});

// 保留域名黑名单管理
Route::prefix('reserved-domains')->group(function () {
    Route::get('/', [ReservedDomainController::class, 'index'])->middleware('rbac.permission:domain.manage');
    Route::put('/', [ReservedDomainController::class, 'update'])->middleware('rbac.permission:domain.manage');
});
