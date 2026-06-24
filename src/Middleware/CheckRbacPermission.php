<?php

namespace MultiTenantSaas\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\RbacService;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC 权限检查中间件
 *
 * 用法: Route::middleware('rbac.permission:tenant.users.create')
 */
class CheckRbacPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!RbacService::check($permission)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => "无权限: {$permission}",
                    'error' => 'Forbidden',
                ], 403);
            }
            abort(403, "无权限: {$permission}");
        }

        return $next($request);
    }
}
