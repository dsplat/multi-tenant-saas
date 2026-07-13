<?php

namespace MultiTenantSaas\Modules\Auth\Http\Middleware;

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
        if (! RbacService::check($permission)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => trans('common.forbidden'),
                    'error' => 'Forbidden',
                    'permission' => $permission,
                ], 403);
            }
            abort(403, trans('common.forbidden'));
        }

        return $next($request);
    }
}
