<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantMemberController;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantSettingController;
use MultiTenantSaas\Modules\User\Http\Controllers\UserController;

Route::middleware('rbac.permission:tenant.view')->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::get('/tenants/{tenantId}', [TenantController::class, 'show']);
});

Route::middleware('rbac.permission:tenant.create')->group(function () {
    Route::post('/tenants', [TenantController::class, 'store']);
});

Route::middleware('rbac.permission:tenant.update')->group(function () {
    Route::put('/tenants/{tenantId}', [TenantController::class, 'update']);
    Route::post('/tenants/{tenantId}/suspend', [TenantController::class, 'suspend']);
    Route::post('/tenants/{tenantId}/activate', [TenantController::class, 'activate']);
});

Route::middleware('rbac.permission:tenant.delete')->group(function () {
    Route::delete('/tenants/{tenantId}', [TenantController::class, 'destroy']);
});

Route::middleware('rbac.permission:member.view')->group(function () {
    Route::get('/tenants/{tenantId}/members', [TenantMemberController::class, 'index']);
});

Route::middleware('rbac.permission:member.create')->group(function () {
    Route::post('/tenants/{tenantId}/members', [TenantMemberController::class, 'store']);
});

Route::middleware('rbac.permission:member.update')->group(function () {
    Route::put('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'update']);
});

Route::middleware('rbac.permission:member.delete')->group(function () {
    Route::delete('/tenants/{tenantId}/members/{userId}', [TenantMemberController::class, 'destroy']);
});

Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tenants/{tenantId}/settings/{group?}', [TenantSettingController::class, 'index']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::put('/tenants/{tenantId}/settings/{group}', [TenantSettingController::class, 'update']);
    Route::post('/tenants/{tenantId}/settings/sms/test', [TenantSettingController::class, 'testSms']);
});

// 用户管理
Route::middleware('rbac.permission:user.view')->group(function () {
    Route::get('/tenants/{tenantId}/users', [UserController::class, 'index']);
    Route::get('/tenants/{tenantId}/users/search', [UserController::class, 'search']);
    Route::get('/tenants/{tenantId}/users/{userId}', [UserController::class, 'show']);
});

Route::middleware('rbac.permission:user.update')->group(function () {
    Route::put('/tenants/{tenantId}/users/{userId}', [UserController::class, 'update']);
});

Route::middleware('rbac.permission:user.delete')->group(function () {
    Route::delete('/tenants/{tenantId}/users/{userId}', [UserController::class, 'destroy']);
});
