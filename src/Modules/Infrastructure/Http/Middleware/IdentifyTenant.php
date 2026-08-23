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
 * 7. app 域路径前缀（app.{base}/{slug}/...，SEO 内容积累形态）
 * 8. 通配子域名解析（{tenant_id}.{base} 直查 / {slug}.{base}）
 * 9. 未识别不兜底（EnsureTenantContext 返 403）
 *
 * 架构约束（2026-08 重新启用）：app 域路径前缀形态（/{slug}/、/{tenant_id}/）
 * 仅限 app 域（domain.platform_domains.app），用于租户内容积累到主域做 SEO
 * （app.neihang.com/{slug}/id-xxx.html ⇔ {slug}.neihang.com/id-xxx.html）；
 * 基础域（wildcard_base 裸域）路径前缀仍不支持。
 * 租户共享入口 = {slug}.{base} / {tenant_id}.{base} 子域 + app/{slug} 路径 双形态。
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

        // 7. app 域路径前缀（app.neihang.com/{slug}/...）——SEO 内容积累形态，
        //    路径第一段即租户标识，与通配子域名同构（16 位 tenant_id 直查 / slug 查询）
        if ($tenantId = $this->resolveFromAppPath($request)) {
            return $tenantId;
        }

        // 8. 通配子域名解析（租户共享入口：{tenant_id}.{base} / {slug}.{base}）
        //    平台自有域名（www/admin/console/api/app）可能与通配 base 后缀重合
        //    （如 console.neihang.com 与 base=neihang.com），不是租户接入域，
        //    禁止进入通配解析/默认租户兜底，否则无租户 Operator 会被误绑默认租户
        $host = $request->header('X-Original-Host') ?? $request->getHost();
        if ($this->isWildcardSubdomain($host) && ! $this->isPlatformDomain($host)) {
            if ($tenantId = $this->resolveFromSubdomain($host)) {
                return $tenantId;
            }

            // 未匹配到租户，兜底到默认租户
            return config('tenancy.default_tenant_id') ? (string) config('tenancy.default_tenant_id') : null;
        }

        // 9. 未识别域名不兜底，由 EnsureTenantContext 返回 403
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
     * 判断是否为平台自有域名（main/admin/console/api 等）
     */
    protected function isPlatformDomain(string $host): bool
    {
        $platformDomains = array_filter(array_merge(
            (array) config('tenancy.platform_domains', []),
            array_values((array) config('domain.platform_domains', []))
        ));

        return in_array($host, $platformDomains, true);
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
     * app 域路径前缀形态：app.neihang.com/{slug}/...
     *
     * 与通配子域名同构（路径第一段 = 租户标识），支持 16 位 tenant_id 直查 /
     * t-xxx 自动码 / 自定义 slug（slug_status=active）。
     * 用于 SEO 内容积累：app.neihang.com/{slug}/id-xxx.html ⇔ {slug}.neihang.com/id-xxx.html。
     * app 裸域（无路径第一段）不是租户入口，返回 null。
     */
    protected function resolveFromAppPath(Request $request): ?string
    {
        $appDomain = config('domain.platform_domains.app');
        if (! $appDomain) {
            return null;
        }

        $host = $request->header('X-Original-Host') ?? $request->getHost();
        if ($host !== $appDomain) {
            return null;
        }

        $firstSegment = strtok(ltrim($request->getPathInfo(), '/'), '/');
        if ($firstSegment === false || $firstSegment === '' || str_contains($firstSegment, '.')) {
            return null; // 裸域 / 多级路径段不作为租户标识
        }

        return $this->resolveFromSlug($firstSegment);
    }

    /**
     * 按租户标识解析租户（子域 label 与 app 路径第一段共用）
     *
     * 两种同质形态：
     *   {tenant_id}（16 位雪花 ID 直查，如 9007199254740992）
     *   {slug}（含自动码 t-xxxxxx，如 lanyantu / t-a3k9z2）
     * 带缓存，避免每次请求查库。
     */
    protected function resolveFromSlug(string $label): ?string
    {
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

        return $this->resolveFromSlug($label);
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
