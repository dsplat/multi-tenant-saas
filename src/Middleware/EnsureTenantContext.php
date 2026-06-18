<?php

namespace MultiTenantSaas\Middleware;

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

        if (!$tenantId || !$tenant) {
            return $this->missingTenantResponse($request);
        }

        if (!$tenant->isActive()) {
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
                'message' => '缺少租户信息',
                'error' => 'MissingTenant',
            ], 400);
        }

        abort(400, '缺少租户信息');
    }

    /**
     * 租户未激活的响应
     */
    protected function inactiveTenantResponse(Request $request, $tenant): Response
    {
        $message = match ($tenant->status) {
            'suspended' => '该租户已被暂停',
            'cancelled' => '该租户已被取消',
            default => '该租户不可用',
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
