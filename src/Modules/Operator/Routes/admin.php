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
    Route::get('/', [OperatorController::class, 'index']);
    Route::post('/invite', [OperatorController::class, 'invite']);
    Route::put('/{operatorId}/role', [OperatorController::class, 'updateRole']);
    Route::delete('/{operatorId}', [OperatorController::class, 'remove']);
    Route::get('/{operatorId}/tenants', [OperatorController::class, 'tenants']);
});
