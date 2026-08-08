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
 * 7. 通配子域名解析（{tenant_id}.{base} 直查 / {slug}.{base}，租户共享入口唯一形态）
 * 8. 未识别不兜底（EnsureTenantContext 返 403）
 *
 * 架构约束：不支持 app 域路径前缀（/{slug}/、/{tenant_id}/）形态——
 * 租户共享入口一律为子域名（{slug}.{base} / {tenant_id}.{base}），
 * 与 nginx 统一基桩白名单同构，避免共享域 cookie 串扰与 SEO 污染。
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
        } else {
            // 解析失败（含 Operator 归属校验不通过）：清除可能由前一次中间件设置的上下文
            TenantContext::setTenant(null);
            TenantContext::setTenantId(null);
        }

        return $next($request);
    }

    /**
     * 按优先级解析租户ID
     */
    protected function resolveTenantId(Request $request): ?string
    {
        // 1. URL参数（不可信，需校验归属；校验不通过则忽略该来源，继续后续解析）
        if ($tenantId = ($request->query('tenant_id') ?? $request->query('tid'))) {
            if ($resolved = $this->resolveWithOwnershipCheck((string) $tenantId, $request)) {
                return $resolved;
            }
        }

        // 2. Header（不可信，需校验归属；校验不通过则忽略——前端残留的过期 X-Tenant-ID
        //    不应导致整个请求 403，域名解析等可信来源仍可自愈；Operator 在步骤 6 中单独处理）
        $tokenable = $request->user();
        if (! ($tokenable instanceof Operator) && $tenantId = $request->header('X-Tenant-ID')) {
            if ($resolved = $this->resolveWithOwnershipCheck((string) $tenantId, $request)) {
                return $resolved;
            }
        }

        // 3. 自定义域名（域名归属可信，但仍需校验 Operator 是否属于该租户）
        if ($tenantId = $this->resolveFromCustomDomain($request)) {
            $tokenable = $request->user();
            if ($tokenable instanceof Operator && $tokenable->scope !== 'platform') {
                $hasAccess = OperatorTenant::where('operator_id', $tokenable->operator_id)
                    ->where('tenant_id', (int) $tenantId)
                    ->where('is_active', true)
                    ->exists();
                if (! $hasAccess) {
                    return null;
                }
            }

            return (string) $tenantId;
        }

        // 4. Cookie（不可信，需校验归属；校验不通过则忽略该来源，继续后续解析）
        if ($tenantId = $request->cookie('tenant_id')) {
            if ($resolved = $this->resolveWithOwnershipCheck((string) $tenantId, $request)) {
                return $resolved;
            }
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

        // 7. 通配子域名解析（租户共享入口唯一形态：{tenant_id}.{base} / {slug}.{base}）
        $host = $request->header('X-Original-Host') ?? $request->getHost();
        if ($this->isWildcardSubdomain($host)) {
            if ($tenantId = $this->resolveFromSubdomain($host)) {
                return $tenantId;
            }

            // 未匹配到租户，兜底到默认租户
            return config('tenancy.default_tenant_id') ? (string) config('tenancy.default_tenant_id') : null;
        }

        // 8. 未识别域名不兜底，由 EnsureTenantContext 返回 403
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
        if (! $user) {
            return $tenantId;
        }

        // Operator：校验 operator_tenants 归属
        if ($user instanceof Operator) {
            if ($user->scope === 'platform') {
                return $tenantId;
            }

            $belongsToTenant = OperatorTenant::where('operator_id', $user->operator_id)
                ->where('tenant_id', (int) $tenantId)
                ->where('is_active', true)
                ->exists();

            return $belongsToTenant ? $tenantId : null;
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
        // 如果请求头指定了 tenant_id，验证 Operator 是否有权访问；
        // 无权访问（如前端残留的过期 X-Tenant-ID）则忽略 header，回退到活跃关联
        if ($headerTenantId = $request->header('X-Tenant-ID')) {
            $hasAccess = OperatorTenant::where('operator_id', $operator->operator_id)
                ->where('tenant_id', (int) $headerTenantId)
                ->where('is_active', true)
                ->exists();

            if ($hasAccess) {
                return (string) $headerTenantId;
            }
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
     * 从通配子域名提取标识并解析租户
     *
     * 两种同质形态（子域名前缀即租户标识）：
     *   {tenant_id}.{wildcard_base}（16 位雪花 ID 直查，如 9007199254740992.dsplat.com）
     *   {slug}.{wildcard_base}（含自动码 t-xxxxxx，如 lanyantu.dsplat.com）
     * 带缓存，避免每次请求查库。
     */
    protected function resolveFromSubdomain(string $host): ?string
    {
        $wildcardBase = config('domain.wildcard_base');
        $label = substr($host, 0, -(strlen($wildcardBase) + 1)); // 去掉 ".dsplat.com"

        if (empty($label) || str_contains($label, '.')) {
            return null; // 多级子域名（如 a.b.dsplat.com）不支持
        }

        // 纯数字且符合雪花 ID 长度（16 位）→ 按 tenant_id 直查
        if (ctype_digit($label) && strlen($label) === 16) {
            $cacheKey = config('tenancy.cache.prefix', 'tenant:') . 'subdomain-id:' . $label;

            $tenantId = cache()->remember(
                $cacheKey,
                config('tenancy.cache.ttl', 3600),
                fn () => Tenant::where('tenant_id', $label)
                    ->where('status', 'active')
                    ->value('tenant_id')
            );

            return $tenantId ? (string) $tenantId : null;
        }

        $cacheKey = config('tenancy.cache.prefix', 'tenant:') . 'slug:' . $label;

        $tenantId = cache()->remember(
            $cacheKey,
            config('tenancy.cache.ttl', 3600),
            fn () => Tenant::where('slug', $label)
                ->where('slug_status', 'active')
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
