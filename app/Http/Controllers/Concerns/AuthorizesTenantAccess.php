<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * 租户访问控制 Trait
 *
 * 确保当前用户有权访问目标租户的数据
 * - super_admin 不能访问租户私有数据
 * - 普通用户必须属于该租户
 */
trait AuthorizesTenantAccess
{
    /**
     * 验证用户是否属于目标租户
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    protected function ensureTenantAccess(Request $request, int $tenantId): void
    {
        $user = $request->user();

        if ($user->role === 'super_admin') {
            abort(403, '系统管理员不能访问租户数据');
        }

        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (!$tenantUser) {
            abort(403, '您不属于该租户');
        }
    }

    /**
     * 验证用户是否为 super_admin
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    protected function ensureSuperAdmin(Request $request): void
    {
        if ($request->user()->role !== 'super_admin') {
            abort(403, '仅超级管理员可访问');
        }
    }
}
