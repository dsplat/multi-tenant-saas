<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantMemberController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantSettingController;

Route::get('/tenants', [TenantController::class, 'index']);
Route::post('/tenants', [TenantController::class, 'store']);
Route::get('/tenants/{tenantId}', [TenantController::class, 'show']);
Route::put('/tenants/{tenantId}', [TenantController::class, 'update']);
Route::delete('/tenants/{tenantId}', [TenantController::class, 'destroy']);
Route::post('/tenants/{tenantId}/suspend', [TenantController::class, 'suspend']);
Route::post('/tenants/{tenantId}/activate', [TenantController::class, 'activate']);

Route::get('/tenants/{tenantId}/members', [TenantMemberController::class, 'index']);
Route::post('/tenants/{tenantId}/members', [TenantMemberController::class, 'store']);
Route::put('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'update']);
Route::delete('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'destroy']);

Route::get('/tenants/{tenantId}/settings/{group?}', [TenantSettingController::class, 'index']);
Route::put('/tenants/{tenantId}/settings/{group}', [TenantSettingController::class, 'update']);
Route::post('/tenants/{tenantId}/settings/sms/test', [TenantSettingController::class, 'testSms']);
