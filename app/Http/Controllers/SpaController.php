<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * SPA 入口控制器
 *
 * 为前端单页应用提供 catch-all 路由支持。
 * 所有非 API 请求均返回对应的 index.html，由前端路由接管。
 */
class SpaController extends Controller
{
    /**
     * 平台首页
     */
    public function index(Request $request)
    {
        // 如果有公开页面，重定向到 /public
        $publicIndex = public_path('public/index.html');
        if (file_exists($publicIndex)) {
            return redirect('/public');
        }

        return response()->json([
            'name' => config('app.name'),
            'version' => '1.0.0',
            'status' => 'ok',
        ]);
    }

    /**
     * 公开页面 SPA（登录/注册/申请/进度查询）
     */
    public function publicPage(Request $request)
    {
        $indexPath = public_path('public/index.html');
        if (file_exists($indexPath)) {
            return response()->file($indexPath);
        }

        return response()->json(['message' => 'Public pages not built yet. Run: cd resources/js/public && npm run build'], 503);
    }

    /**
     * 租户管理后台 SPA
     */
    public function console(Request $request)
    {
        return response()->file(public_path('console/index.html'));
    }

    /**
     * 系统管理后台 SPA
     */
    public function admin(Request $request)
    {
        return response()->file(public_path('admin/index.html'));
    }
}
