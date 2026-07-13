<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Operator\Http\Controllers\OperatorController;

/*
|--------------------------------------------------------------------------
| Operator Public Routes
|--------------------------------------------------------------------------
|
| 公开路由（接受邀请等无需认证的操作）
|
*/

Route::post('/operator/accept-invite', [OperatorController::class, 'acceptInvite']);
