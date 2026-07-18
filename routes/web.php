<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// 平台首页
Route::get('/', [SpaController::class, 'index']);

// 公开页面 SPA（登录/注册/申请/进度查询）
Route::prefix('public')->group(function () {
    Route::get('/', [SpaController::class, 'publicPage']);
    Route::get('/{any}', [SpaController::class, 'publicPage'])->where('any', '.*');
});

// 系统后台路由（admin 域名专用）
Route::prefix('admin')->group(function () {
    Route::get('/', [SpaController::class, 'admin']);
    Route::get('/{any}', [SpaController::class, 'admin'])->where('any', '.*');
});

// 租户后台路由
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [SpaController::class, 'console']);
    Route::get('/{any}', [SpaController::class, 'console'])->where('any', '.*');
});
