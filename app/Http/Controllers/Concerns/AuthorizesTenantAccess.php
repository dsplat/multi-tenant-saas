<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 团队访问控制 Trait（Operator 直连团队模式）
 *
 * - 平台 Operator（scope=platform）不能访问团队私有数据
 * - 普通用户必须属于该团队
 */
trait AuthorizesTenantAccess
{
    protected function ensureTenantAccess(Request $request, ?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        $user = $request->user();

        // 平台级 Operator 处理
        if ($user instanceof Operator && $user->scope === 'platform') {
            $platformTenantId = config('id.platform_tenant_id', 9007199254740991);
            if ((int) $tenantId === (int) $platformTenantId) {
                return;
            }
            abort(403, '系统管理员不能访问团队私有数据');
        }

        // Operator 直连路径：通过 operator_tenants 检查是否在该团队
        if ($user instanceof Operator) {
            $inTenant = DB::table('operator_tenants')
                ->where('operator_id', $user->operator_id)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists();

            if (! $inTenant) {
                abort(403, '您不属于该团队');
            }

            return;
        }

        // User 路径：通过 tenant_users 检查
        $exists = TenantUser::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->user_id)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            abort(403, '您不属于该团队');
        }
    }

    protected function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();

        if (! ($user instanceof Operator) || $user->scope !== 'platform') {
            abort(403, '仅超级管理员可访问');
        }
    }
}
