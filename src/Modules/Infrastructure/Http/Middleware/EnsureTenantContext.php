<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * 租户上下文验证中间件
 *
 * 确保在需要租户上下文的请求中：
 * 1. 租户信息已正确识别
 * 2. 租户状态为激活
 */
class EnsureTenantContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $domainType = TenantContext::getDomainType();

        // admin域名不需要租户上下文
        if ($domainType === 'admin') {
            return $next($request);
        }

        $tenantId = TenantContext::getId();
        $tenant = TenantContext::getTenant();

        if (! $tenantId || ! $tenant) {
            return $this->missingTenantResponse($request);
        }

        if (! $tenant->isActive()) {
            return $this->inactiveTenantResponse($request, $tenant);
        }

        return $next($request);
    }

    /**
     * 缺少租户信息的响应
     */
    protected function missingTenantResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => trans('common.domain_not_recognized'),
                'error' => 'DomainNotRecognized',
            ], 403);
        }

        abort(403, trans('common.domain_not_recognized'));
    }

    /**
     * 租户未激活的响应
     */
    protected function inactiveTenantResponse(Request $request, $tenant): Response
    {
        $message = match ($tenant->status) {
            'suspended' => trans('tenant.suspended'),
            'cancelled' => trans('tenant.cancelled'),
            default => trans('tenant.inactive'),
        };

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => 'InactiveTenant',
            ], 403);
        }

        abort(403, $message);
    }
}
