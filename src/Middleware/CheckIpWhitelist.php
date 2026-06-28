<?php

namespace MultiTenantSaas\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Services\IpWhitelistService;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP 白名单中间件
 *
 * 在 IdentifyTenant 之后执行：
 *  - 仅当租户上下文存在时生效
 *  - 仅当租户已配置白名单时触发拦截（无白名单则放行）
 *  - 根据请求路径判断 scope（api / admin / all）
 *  - 未命中白名单返回 403
 */
class CheckIpWhitelist
{
    public function __construct(
        private readonly IpWhitelistService $whitelistService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Admin 域名不受租户白名单约束
        if (TenantContext::getDomainType() === 'admin') {
            return $next($request);
        }

        $tenantId = TenantContext::getId();
        if (! $tenantId) {
            return $next($request);
        }

        // 未启用白名单则放行
        $scope = $this->resolveScope($request);
        if (! $this->whitelistService->hasActiveWhitelist($scope)) {
            return $next($request);
        }

        $ip = $request->ip() ?: '';

        if (! $this->whitelistService->isAllowed($ip, $scope)) {
            return response()->json([
                'success' => false,
                'message' => trans('tenant.ip_whitelist_blocked'),
                'error_code' => 'IP_NOT_ALLOWED',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * 根据请求解析白名单 scope
     */
    protected function resolveScope(Request $request): string
    {
        if ($request->is('api/*') || $request->is('*/api/*')) {
            return IpWhitelistService::SCOPE_API;
        }

        if ($request->is('admin/*') || str_contains($request->getPathInfo(), '/admin')) {
            return IpWhitelistService::SCOPE_ADMIN;
        }

        return IpWhitelistService::SCOPE_ALL;
    }
}
