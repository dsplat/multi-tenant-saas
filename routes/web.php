<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA 入口路由
|--------------------------------------------------------------------------
|
| 架构原则：
|  - /           → 平台首页 SPA (public/index.html)
|  - /admin/*    → Admin SPA (public/admin/index.html)
|  - /console/*  → Console SPA (public/console/index.html)
|  - /api/*      → Laravel API（在 routes/api.php 中定义）
|  - 其他 GET    → 兜底到平台 SPA（Vue Router 接管前端路由）
|
*/

// 平台首页 + 兜底（前端路由如 /login、/register 由 Vue Router 接管）
Route::get('/', [SpaController::class, 'index']);
Route::fallback([SpaController::class, 'index']);

// 系统后台 SPA（admin 域名专用）
Route::prefix('admin')->group(function () {
    Route::get('/', [SpaController::class, 'admin']);
    Route::get('/{any}', [SpaController::class, 'admin'])->where('any', '.*');
});

// 租户后台 SPA
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [SpaController::class, 'console']);
    Route::get('/{any}', [SpaController::class, 'console'])->where('any', '.*');
});
