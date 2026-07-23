<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * 租户识别中间件
 *
 * 按优先级识别租户：
 * 1. URL参数 ?tenant_id=xxx（需校验用户归属）
 * 2. Header X-Tenant-ID（需校验用户归属）
 * 3. 自定义域名（可信：域名本身即归属证明）
 * 4. Cookie（需校验用户归属）
 * 5. Session
 * 6. 认证用户
 * 7. 默认租户
 *
 * 安全原则：不可信来源（URL/Header/Cookie）解析的租户，
 * 必须校验已认证用户确实属于该租户，防止越权。
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
        // 1. URL参数（不可信，需校验归属）
        if ($tenantId = ($request->query('tenant_id') ?? $request->query('tid'))) {
            return $this->resolveWithOwnershipCheck((string) $tenantId, $request);
        }

        // 2. Header（不可信，需校验归属；Operator 在步骤 6 中单独处理）
        $tokenable = $request->user();
        if (! ($tokenable instanceof Operator) && $tenantId = $request->header('X-Tenant-ID')) {
            return $this->resolveWithOwnershipCheck((string) $tenantId, $request);
        }

        // 3. 自定义域名（可信：域名归属由平台管理，无需校验用户归属）
        if ($tenantId = $this->resolveFromCustomDomain($request)) {
            return (string) $tenantId;
        }

        // 4. Cookie（不可信，需校验归属）
        if ($tenantId = $request->cookie('tenant_id')) {
            return $this->resolveWithOwnershipCheck((string) $tenantId, $request);
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

        // 7. 通配子域名解析（如 lanyantu.dsplat.com → slug=lanyantu）
        $host = $request->header('X-Original-Host') ?? $request->getHost();
        if ($this->isWildcardSubdomain($host)) {
            // 提取子域名前缀作为 slug 查找租户
            if ($tenantId = $this->resolveFromSubdomain($host)) {
                return $tenantId;
            }

            // 未匹配到租户，兜底到默认租户
            return config('tenancy.default_tenant_id') ? (string) config('tenancy.default_tenant_id') : null;
        }

        // 未识别域名不兜底，由 EnsureTenantContext 返回 403
        return null;
    }

    /**
     * 对不可信来源的租户 ID 进行用户归属校验。
     *
     * - 未认证用户（公开路由）：允许通过（由后续中间件决定是否放行）
     * - 已认证用户：必须属于该租户（tenant_users 表有记录且 is_active）
     */
    protected function resolveWithOwnershipCheck(string $tenantId, Request $request): ?string
    {
        $user = $request->user();

        // 未认证请求不做归属校验（公开页面、OAuth 回调等）
        if (! $user || $user instanceof Operator) {
            return $tenantId;
        }

        // 已认证用户：校验归属
        $belongsToTenant = DB::table('tenant_users')
            ->where('user_id', $user->getKey())
            ->where('tenant_id', (int) $tenantId)
            ->where('is_active', true)
            ->exists();

        return $belongsToTenant ? $tenantId : null;
    }

    /**
     * 从租户域名识别租户
     *
     * 统一使用 tenants.domain 字段（custom_domain 已废弃合并）。
     */
    protected function resolveFromCustomDomain(Request $request): ?string
    {
        $host = $request->header('X-Original-Host') ?? $request->getHost();

        // 排除平台域名
        $platformDomains = config('tenancy.platform_domains', []);
        if (in_array($host, $platformDomains)) {
            return null;
        }

        return Tenant::where('domain', $host)
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
     * 判断是否为平台通配子域名（如 arthur.scrm.com）
     */
    protected function isWildcardSubdomain(string $host): bool
    {
        $wildcardBase = config('domain.wildcard_base');

        if (! $wildcardBase) {
            return false;
        }

        return str_ends_with($host, ".{$wildcardBase}") && $host !== $wildcardBase;
    }

    /**
     * 从通配子域名提取 slug 并解析租户
     *
     * 例：lanyantu.dsplat.com → 提取 "lanyantu" → 查 tenants.slug
     * 带缓存，避免每次请求查库。
     */
    protected function resolveFromSubdomain(string $host): ?string
    {
        $wildcardBase = config('domain.wildcard_base');
        $slug = substr($host, 0, -(strlen($wildcardBase) + 1)); // 去掉 ".dsplat.com"

        if (empty($slug) || str_contains($slug, '.')) {
            return null; // 多级子域名（如 a.b.dsplat.com）不支持
        }

        $cacheKey = config('tenancy.cache.prefix', 'tenant:') . 'slug:' . $slug;

        $tenantId = cache()->remember(
            $cacheKey,
            config('tenancy.cache.ttl', 3600),
            fn () => Tenant::where('slug', $slug)
                ->where('status', 'active')
                ->value('tenant_id')
        );

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
