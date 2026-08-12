<?php

use App\Http\Controllers\SpaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyDomain;

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
// 专属平台域裸根收敛到各自 SPA：域类型由 IdentifyDomain 中间件正向判定，
// 单域名部署（未配置 admin/console 专属域）不受影响
Route::get('/', function (Request $request) {
    $domainType = TenantContext::getDomainType();
    if ($domainType === IdentifyDomain::DOMAIN_ADMIN) {
        return redirect('/admin/');
    }
    if ($domainType === IdentifyDomain::DOMAIN_CONSOLE) {
        return redirect('/console/');
    }

    return app(SpaController::class)->index($request);
});
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
