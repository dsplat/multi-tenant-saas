<?php

namespace MultiTenantSaas\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use Symfony\Component\HttpFoundation\Response;

/**
 * 权限控制中间件（Operator 直连团队模式）
 *
 * 安全原则：
 * - 平台 admin 后台仅 scope=platform 的 Operator 可访问
 * - 团队 console 后台仅 tenant_admin 角色 Operator 可访问
 * - 团队前台 /app 由 User（经 tenant_users 关联，不拥有角色）或 Operator（经 operator_tenants）访问
 */
class CheckPermission
{
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
     * 检查平台 admin 后台访问权限：仅 scope=platform 的 Operator 可访问
     */
    protected function checkAdminAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        if (! ($user instanceof Operator) || $user->scope !== 'platform') {
            return $this->forbidden($request, trans('common.super_admin_only'));
        }

        return $next($request);
    }

    /**
     * 检查团队 console 后台访问权限：仅通过 operator_tenants 关联的 tenant_admin 可访问
     */
    protected function checkConsoleAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        $tenantId = TenantContext::getId();

        if (! $tenantId) {
            return $this->forbidden($request, trans('common.missing_tenant'));
        }

        // Operator 直连路径
        if ($user instanceof Operator) {
            $operatorTenant = DB::table('operator_tenants')
                ->where('operator_id', $user->operator_id)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();

            if (! $operatorTenant) {
                return $this->forbidden($request, trans('common.not_in_tenant'));
            }

            // console 仅允许 tenant_admin 角色（全局角色与租户专属角色均放行：
            // 租户可能存在专属 tenant_admin 角色行，单行取值会误拒绑定全局角色的运营者）
            $tenantAdminRoleIds = DB::table('roles')
                ->where('name', 'tenant_admin')
                ->where(function ($q) use ($tenantId) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                })
                ->pluck('role_id')
                ->all();

            if (! in_array($operatorTenant->role_id, $tenantAdminRoleIds, true)) {
                return $this->forbidden($request, trans('common.tenant_admin_only'));
            }

            TenantContext::setTenantRole('tenant_admin');

            return $next($request);
        }

        // User 路径（开放注册后的业务用户，原则上不允许进 console）
        return $this->forbidden($request, trans('common.tenant_admin_only'));
    }

    /**
     * 检查团队访问权限：Operator 通过 operator_tenants，User 通过 tenant_users
     */
    protected function checkTenantAccess(Request $request, $user, Closure $next, ?string $role): Response
    {
        $tenantId = TenantContext::getId();

        if (! $tenantId) {
            return $this->forbidden($request, trans('common.missing_tenant'));
        }

        $tenantRoleName = null;

        // Operator 直连路径
        if ($user instanceof Operator) {
            $operatorTenant = DB::table('operator_tenants')
                ->where('operator_id', $user->operator_id)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();

            if (! $operatorTenant) {
                return $this->forbidden($request, trans('common.not_in_tenant'));
            }

            $tenantRoleName = DB::table('roles')->where('role_id', $operatorTenant->role_id)->value('name');
        } else {
            // User 路径（User 不拥有角色，仅校验是否为该租户的活跃用户）
            $tenantUser = $user->tenants()
                ->where('tenants.tenant_id', $tenantId)
                ->wherePivot('is_active', true)
                ->first();

            if (! $tenantUser) {
                return $this->forbidden($request, trans('common.not_in_tenant'));
            }

            // User 无角色，$tenantRoleName 保持 null
        }

        TenantContext::setTenantRole($tenantRoleName);

        if ($role && $tenantRoleName !== $role) {
            return $this->forbidden($request, trans('common.role_required', ['role' => $role]));
        }

        return $next($request);
    }

    protected function unauthorized(Request $request): Response
    {
        $domainType = TenantContext::getDomainType();
        $path = $request->getPathInfo();

        if ($request->expectsJson()
            || in_array($domainType, ['admin', 'console', 'api'])
            || str_starts_with($path, '/admin')
            || str_starts_with($path, '/console')
            || str_starts_with($path, '/api')) {
            return response()->json(['message' => trans('common.unauthenticated'), 'error' => 'Unauthenticated'], 401);
        }

        if (Route::has('login')) {
            return redirect()->guest(route('login'));
        }

        return response()->json(['message' => 'Unauthenticated'], 401);
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
