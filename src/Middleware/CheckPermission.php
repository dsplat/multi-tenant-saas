<?php

namespace MultiTenantSaas\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * 权限控制中间件
 *
 * 根据域名类型和用户角色进行权限控制
 */
class CheckPermission
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_TENANT_ADMIN = 'tenant_admin';
    public const ROLE_END_USER = 'end_user';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthorized($request);
        }

        $domainType = TenantContext::getDomainType();

        return match ($domainType) {
            'admin' => $this->checkAdminAccess($request, $user, $next, $role),
            'console', 'api' => $this->checkTenantAccess($request, $user, $next, $role),
            default => $next($request),
        };
    }

    /**
     * 检查管理后台访问权限
     */
    protected function checkAdminAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        if ($user->role !== self::ROLE_SUPER_ADMIN) {
            return $this->forbidden($request, '仅超级管理员可以访问');
        }

        return $next($request);
    }

    /**
     * 检查租户访问权限
     */
    protected function checkTenantAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        $tenantId = TenantContext::getId();

        if (!$tenantId) {
            return $this->forbidden($request, '缺少租户信息');
        }

        // super_admin 可以访问所有租户
        if ($user->role === self::ROLE_SUPER_ADMIN) {
            TenantContext::setTenantRole(self::ROLE_SUPER_ADMIN);
            return $next($request);
        }

        // 检查用户是否属于该租户
        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (!$tenantUser) {
            return $this->forbidden($request, '您不属于该租户');
        }

        $tenantRole = $tenantUser->pivot->role;
        TenantContext::setTenantRole($tenantRole);

        // 检查指定角色
        if ($role && $tenantRole !== $role) {
            return $this->forbidden($request, "需要 {$role} 角色权限");
        }

        return $next($request);
    }

    protected function unauthorized(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => '未登录', 'error' => 'Unauthenticated'], 401);
        }
        return redirect()->guest(route('login'));
    }

    protected function forbidden(Request $request, string $message = '无权访问'): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'error' => 'Forbidden'], 403);
        }
        abort(403, $message);
    }
}
