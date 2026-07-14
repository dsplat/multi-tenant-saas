<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Operator\Http\Controllers\OperatorController;

/*
|--------------------------------------------------------------------------
| Operator Admin Routes
|--------------------------------------------------------------------------
|
| 平台管理员管理运营人员的路由
|
*/

Route::prefix('operators')->group(function () {
    Route::get('/', [OperatorController::class, 'index'])->middleware('rbac.permission:member.view');
    Route::post('/invite', [OperatorController::class, 'invite'])->middleware('rbac.permission:member.create');
    Route::put('/{operatorId}/role', [OperatorController::class, 'updateRole'])->middleware('rbac.permission:member.update');
    Route::delete('/{operatorId}', [OperatorController::class, 'remove'])->middleware('rbac.permission:member.delete');
    Route::get('/{operatorId}/tenants', [OperatorController::class, 'tenants'])->middleware('rbac.permission:member.view');
});
