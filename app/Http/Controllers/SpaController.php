<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * SPA 入口控制器
 *
 * 为前端单页应用提供 catch-all 路由支持。
 * 所有非 API 请求均返回对应的 index.html，由前端路由接管。
 *
 * 架构：
 *  - /           → 平台首页 SPA (public/index.html)
 *  - /admin/*    → Admin SPA (public/admin/index.html)
 *  - /console/*  → Console SPA (public/console/index.html)
 *  - /api/*      → Laravel API（不由此控制器处理）
 */
class SpaController extends Controller
{
    /**
     * 平台首页 SPA
     *
     * 直接返回 public/index.html，不再重定向到 /public。
     * 前端路由（如 /login, /register）由 Vue Router 接管。
     */
    public function index(Request $request)
    {
        $indexPath = public_path('index.html');
        if (file_exists($indexPath)) {
            return response()->file($indexPath);
        }

        // SPA 未构建时返回基础状态信息（开发环境友好）
        return response()->json([
            'name' => config('app.name'),
            'version' => '1.0.0',
            'status' => 'ok',
            'message' => 'SPA not built yet. Run: cd resources/js/public && npm run build',
        ], 503);
    }

    /**
     * 系统管理后台 SPA
     */
    public function admin(Request $request)
    {
        $indexPath = public_path('admin/index.html');
        if (file_exists($indexPath)) {
            return response()->file($indexPath);
        }

        return response()->json([
            'message' => 'Admin SPA not built yet. Run: cd resources/js/admin && npm run build',
        ], 503);
    }

    /**
     * 租户管理后台 SPA
     */
    public function console(Request $request)
    {
        $indexPath = public_path('console/index.html');
        if (file_exists($indexPath)) {
            return response()->file($indexPath);
        }

        return response()->json([
            'message' => 'Console SPA not built yet. Run: cd resources/js/console && npm run build',
        ], 503);
    }
}
