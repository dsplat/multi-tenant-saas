<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
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

        // Platform Operator（scope=platform）不需要租户隔离
        $tokenable = $request->user();
        if ($tokenable instanceof Operator && $tokenable->scope === 'platform') {
            return $next($request);
        }

        $tenantId = $this->resolveTenantId($request);

        if ($tenantId) {
            $tenant = $this->loadTenant((int) $tenantId);

            if ($tenant && $tenant->isActive()) {
                TenantContext::setTenant($tenant);
                TenantContext::setTenantId((string) $tenantId);
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

        // 2. Header（Operator 的租户权限验证在步骤 6 中处理）
        $tokenable = $request->user();
        if (! ($tokenable instanceof Operator) && $tenantId = $request->header('X-Tenant-ID')) {
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

        // 6. 认证用户 — 支持 User 和 Operator 两种 tokenable 类型
        $tokenable = $request->user();
        if ($tokenable instanceof Operator) {
            return $this->resolveTenantFromOperator($tokenable, $request);
        }
        if ($tokenable && property_exists($tokenable, 'current_tenant_id') && $tokenable->current_tenant_id) {
            return (string) $tokenable->current_tenant_id;
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
     * 从 Operator 关联解析租户 ID
     *
     * 优先级：
     * 1. Header X-Tenant-ID（多租户 Operator 切换租户）
     * 2. OperatorTenant 中第一个活跃关联
     */
    protected function resolveTenantFromOperator(Operator $operator, Request $request): ?string
    {
        // 如果请求头指定了 tenant_id，验证 Operator 是否有权访问
        if ($headerTenantId = $request->header('X-Tenant-ID')) {
            $hasAccess = OperatorTenant::where('operator_id', $operator->operator_id)
                ->where('tenant_id', (int) $headerTenantId)
                ->where('is_active', true)
                ->exists();

            return $hasAccess ? (string) $headerTenantId : null;
        }

        // 取第一个活跃的 OperatorTenant 关联
        $tenantId = OperatorTenant::where('operator_id', $operator->operator_id)
            ->where('is_active', true)
            ->value('tenant_id');

        return $tenantId ? (string) $tenantId : null;
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
