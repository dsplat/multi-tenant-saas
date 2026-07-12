<?php

namespace MultiTenantSaas\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * 租户识别中间件
 *
 * 按优先级识别租户：
 * 1. URL参数 ?tenant_id=xxx
 * 2. Header X-Tenant-ID
 * 3. 自定义域名
 * 4. Cookie
 * 5. Session
 * 6. 认证用户
 * 7. 默认租户
 */
class IdentifyTenant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Admin域名不需要租户隔离
        if (TenantContext::getDomainType() === 'admin') {
            return $next($request);
        }

        $tenantId = $this->resolveTenantId($request);

        if ($tenantId) {
            $tenant = $this->loadTenant($tenantId);

            if ($tenant && $tenant->isActive()) {
                TenantContext::setTenant($tenant);
                TenantContext::setId($tenantId);
            }
        }

        return $next($request);
    }

    /**
     * 按优先级解析租户ID
     */
    protected function resolveTenantId(Request $request): ?string
    {
        // 1. URL参数
        if ($tenantId = ($request->query('tenant_id') ?? $request->query('tid'))) {
            return (string) $tenantId;
        }

        // 2. Header
        if ($tenantId = $request->header('X-Tenant-ID')) {
            return (string) $tenantId;
        }

        // 3. 自定义域名
        if ($tenantId = $this->resolveFromCustomDomain($request)) {
            return (string) $tenantId;
        }

        // 4. Cookie
        if ($tenantId = $request->cookie('tenant_id')) {
            return (string) $tenantId;
        }

        // 5. Session
        if ($request->hasSession() && $tenantId = $request->session()->get('tenant_id')) {
            return (string) $tenantId;
        }

        // 6. 认证用户 — 仅读取预设属性，不自动查询
        if ($user = $request->user()) {
            if (property_exists($user, 'current_tenant_id') && $user->current_tenant_id) {
                return (string) $user->current_tenant_id;
            }
        }

        // 7. 默认租户（仅限单租户/独立部署模式）
        return config('tenancy.default_tenant_id') ? (string) config('tenancy.default_tenant_id') : null;
    }

    /**
     * 从自定义域名识别租户
     */
    protected function resolveFromCustomDomain(Request $request): ?string
    {
        $host = $request->header('X-Original-Host') ?? $request->getHost();

        // 排除平台域名
        $platformDomains = config('tenancy.platform_domains', []);
        if (in_array($host, $platformDomains)) {
            return null;
        }

        return Tenant::where('custom_domain', $host)
            ->where('status', 'active')
            ->value('tenant_id');
    }

    /**
     * 加载租户（带缓存）
     */
    protected function loadTenant(int $tenantId): ?Tenant
    {
        $cacheKey = config('tenancy.cache.prefix', 'tenant:') . $tenantId;

        return cache()->remember(
            $cacheKey,
            config('tenancy.cache.ttl', 3600),
            fn () => Tenant::find($tenantId)
        );
    }
}
