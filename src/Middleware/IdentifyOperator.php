<?php

namespace MultiTenantSaas\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\OperatorTenant;
use Symfony\Component\HttpFoundation\Response;

class IdentifyOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $domainType = TenantContext::getDomainType();

        if (! in_array($domainType, ['admin', 'console'])) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $operatorTenant = OperatorTenant::where('user_id', $user->user_id)
            ->where('tenant_id', TenantContext::getId())
            ->where('is_active', true)
            ->first();

        if ($operatorTenant) {
            $request->attributes->set('operator_id', $operatorTenant->operator_id);
            $request->attributes->set('operator_role', $operatorTenant->role);
        }

        return $next($request);
    }
}
