<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * 验证已认证 Operator 是否属于当前域名对应的租户
 *
 * 必须在 auth:sanctum 之后执行（依赖 $request->user()）。
 * 解决 IdentifyTenant 在 api 组中先于 auth 执行、无法校验 Operator 归属的问题。
 *
 * 逻辑：
 *  - 非 Operator 用户（普通 User）：放行（由 tenant_users + TenantScope 保障）
 *  - platform scope Operator：放行（平台管理员可跨租户）
 *  - tenant scope Operator：必须在 operator_tenants 中有当前租户的活跃记录
 */
class VerifyOperatorTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 非 Operator（普通 User 或未认证）：不在此中间件处理
        if (! $user instanceof Operator) {
            return $next($request);
        }

        // platform scope 可跨租户
        if ($user->scope === 'platform') {
            return $next($request);
        }

        // 必须用 hasExplicitTenant()：getId() 带 default_tenant_id 兜底恒非 null，
        // 平台专属域（console 等）上未显式识别租户的无租户 Operator
        // 若按兜底默认租户校验归属会被误拒（无限登录循环回归点）
        if (! TenantContext::hasExplicitTenant()) {
            // 无租户上下文（公开路由或域名未识别），交由后续中间件处理
            return $next($request);
        }

        $tenantId = TenantContext::getId();

        $belongsToTenant = OperatorTenant::where('operator_id', $user->operator_id)
            ->where('tenant_id', (int) $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $belongsToTenant) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this workspace.',
                'error' => 'TenantAccessDenied',
            ], 403);
        }

        return $next($request);
    }
}
