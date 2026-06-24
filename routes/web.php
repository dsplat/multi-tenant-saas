<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

// 平台首页
Route::get('/', [TestController::class, 'index']);

// 系统后台路由（admin 域名专用）
Route::prefix('admin')->group(function () {
    Route::get('/', [TestController::class, 'admin']);
    Route::get('/{any}', [TestController::class, 'admin'])->where('any', '.*');
});

// 租户后台路由
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [TestController::class, 'console']);
    Route::get('/{any}', [TestController::class, 'console'])->where('any', '.*');
});
