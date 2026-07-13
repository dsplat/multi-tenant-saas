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
 *
 * 安全原则：
 * - super_admin 仅可访问系统后台 (admin)
 * - 租户私有数据对 super_admin 不可访问
 * - 租户后台仅 tenant_admin 可访问
 * - 用户前台 tenant_admin + end_user 可访问
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

        if (! $user) {
            return $this->unauthorized($request);
        }

        $domainType = TenantContext::getDomainType();

        return match ($domainType) {
            'admin' => $this->checkAdminAccess($request, $user, $next, $role),
            'console' => $this->checkConsoleAccess($request, $user, $next, $role),
            'api', 'app' => $this->checkTenantAccess($request, $user, $next, $role),
            default => $next($request),
        };
    }

    /**
     * 检查管理后台访问权限（仅 super_admin）
     */
    protected function checkAdminAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        if ($user->role !== self::ROLE_SUPER_ADMIN) {
            return $this->forbidden($request, trans('common.super_admin_only'));
        }

        return $next($request);
    }

    /**
     * 检查租户后台访问权限（仅 tenant_admin，super_admin 不可访问）
     */
    protected function checkConsoleAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        $tenantId = TenantContext::getId();

        if (! $tenantId) {
            return $this->forbidden($request, trans('common.missing_tenant'));
        }

        // super_admin 不能访问租户后台（租户数据隔离）
        if ($user->role === self::ROLE_SUPER_ADMIN) {
            return $this->forbidden($request, trans('common.admin_no_tenant_console'));
        }

        // 检查用户是否属于该租户
        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $tenantUser) {
            return $this->forbidden($request, trans('common.not_in_tenant'));
        }

        $tenantRole = $tenantUser->pivot->role;

        // console 仅允许 tenant_admin
        if ($tenantRole !== self::ROLE_TENANT_ADMIN) {
            return $this->forbidden($request, trans('common.tenant_admin_only'));
        }

        TenantContext::setTenantRole($tenantRole);

        return $next($request);
    }

    /**
     * 检查租户访问权限（super_admin 不可访问租户私有数据）
     */
    protected function checkTenantAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        $tenantId = TenantContext::getId();

        if (! $tenantId) {
            return $this->forbidden($request, trans('common.missing_tenant'));
        }

        // super_admin 不能访问租户私有数据
        if ($user->role === self::ROLE_SUPER_ADMIN) {
            return $this->forbidden($request, trans('common.admin_no_tenant_data'));
        }

        // 检查用户是否属于该租户
        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $tenantUser) {
            return $this->forbidden($request, trans('common.not_in_tenant'));
        }

        $tenantRole = $tenantUser->pivot->role;
        TenantContext::setTenantRole($tenantRole);

        // 检查指定角色
        if ($role && $tenantRole !== $role) {
            return $this->forbidden($request, trans('common.role_required', ['role' => $role]));
        }

        return $next($request);
    }

    protected function unauthorized(Request $request): Response
    {
        $domainType = TenantContext::getDomainType();

        // Admin/Console 域名始终返回 JSON（SPA 模式）
        if ($request->expectsJson() || in_array($domainType, ['admin', 'console'])) {
            return response()->json(['message' => trans('common.unauthenticated'), 'error' => 'Unauthenticated'], 401);
        }

        return redirect()->guest(route('login'));
    }

    protected function forbidden(Request $request, ?string $message = null): Response
    {
        $message = $message ?? trans('common.forbidden');
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'error' => 'Forbidden'], 403);
        }
        abort(403, $message);
    }
}
