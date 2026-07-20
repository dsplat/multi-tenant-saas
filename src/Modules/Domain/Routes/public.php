<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Domain\Http\Controllers\TenantResolveController;

/*
|--------------------------------------------------------------------------
| Domain Public Routes
|--------------------------------------------------------------------------
|
| 公开路由（无需认证），供前端登录页在用户认证前获取租户信息。
|
*/

Route::get('/tenant/resolve', [TenantResolveController::class, 'resolve'])
    ->middleware('throttle:30,1');

Route::get('/tenant/login-config', [TenantResolveController::class, 'loginConfig'])
    ->middleware('throttle:30,1');
