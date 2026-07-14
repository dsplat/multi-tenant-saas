<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 租户访问控制 Trait
 *
 * 确保当前用户有权访问目标租户的数据
 * - 平台 operator 不能访问租户私有数据
 * - 普通用户必须属于该租户
 */
trait AuthorizesTenantAccess
{
    /**
     * 验证用户是否属于目标租户
     *
     * @throws HttpException
     */
    protected function ensureTenantAccess(Request $request, ?int $tenantId): void
    {
        // 平台级路由无 tenantId，跳过租户访问检查
        if ($tenantId === null) {
            return;
        }

        $user = $request->user();

        // 检查是否是平台级 operator（平台 operator 不能直接访问租户数据）
        $isPlatformOperator = DB::table('operator_tenants')
            ->join('operators', 'operators.operator_id', '=', 'operator_tenants.operator_id')
            ->where('operator_tenants.user_id', $user->user_id)
            ->where('operators.scope', 'platform')
            ->where('operator_tenants.is_active', true)
            ->exists();

        if ($isPlatformOperator) {
            abort(403, '系统管理员不能访问租户数据');
        }

        // 直接查询 tenant_users 表
        $exists = TenantUser::where('user_id', $user->user_id)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            abort(403, '您不属于该租户');
        }
    }

    /**
     * 验证用户是否为平台 operator
     *
     * @throws HttpException
     */
    protected function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();

        $isPlatformOperator = DB::table('operator_tenants')
            ->join('operators', 'operators.operator_id', '=', 'operator_tenants.operator_id')
            ->where('operator_tenants.user_id', $user->user_id)
            ->where('operators.scope', 'platform')
            ->where('operator_tenants.is_active', true)
            ->exists();

        if (! $isPlatformOperator) {
            abort(403, '仅超级管理员可访问');
        }
    }
}
