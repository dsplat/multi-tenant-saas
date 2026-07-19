<?php

namespace MultiTenantSaas\Modules\Operator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * IdentifyOperator — 在 console/admin 域名下识别当前请求的 Operator 身份
 *
 * Operator 直连租户模式：直接从 Bearer token 解析 Operator，
 * 然后通过 operator_tenants 取其在当前团队中的角色。
 *
 * 不再依赖 User 中转——operator_tenants.user_id 已被删除。
 */
class IdentifyOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $domainType = TenantContext::getDomainType();

        if (! in_array($domainType, ['admin', 'console'])) {
            return $next($request);
        }

        $operator = $this->resolveOperatorFromBearer($request);
        if (! $operator) {
            return $next($request);
        }

        // 让后续 $request->user() 能拿到 Operator
        $request->setUserResolver(fn () => $operator);

        $tenantId = TenantContext::getId();
        if ($tenantId) {
            $operatorTenant = OperatorTenant::where('operator_id', $operator->operator_id)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();

            if ($operatorTenant) {
                $request->attributes->set('operator_id', $operatorTenant->operator_id);
                $request->attributes->set('operator_role', $operatorTenant->role);
            }
        }

        return $next($request);
    }

    /**
     * 从 Authorization Bearer 头解析 Operator 实例
     */
    protected function resolveOperatorFromBearer(Request $request): ?Operator
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $plainText = trim(substr($header, 7));
        if ($plainText === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plainText);
        if (! $accessToken) {
            return null;
        }

        $tokenable = $accessToken->tokenable;

        return $tokenable instanceof Operator ? $tokenable : null;
    }
}
