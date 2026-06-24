<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * SPA 入口控制器
 *
 * 为前端单页应用提供 catch-all 路由支持。
 * 所有非 API 请求均返回对应的 index.html，由前端路由接管。
 */
class TestController extends Controller
{
    /**
     * 平台首页
     */
    public function index(Request $request)
    {
        return response()->json([
            'name' => config('app.name'),
            'version' => '1.0.0',
            'status' => 'ok',
        ]);
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
