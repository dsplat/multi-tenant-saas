<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use Symfony\Component\HttpFoundation\Response;

/**
 * canonical 入口收敛中间件
 *
 * 一个租户存在最多四种入口（自定义域名 / 二级域名 / slug 路径 / tenant_id 路径），
 * 全部可解析，但规范入口唯一，其余 301 收敛（docs/tenant.md §2.0）：
 *
 *   canonical(tenant) =
 *       自定义域名（domain 非空 且 domain_status=approved）
 *       > {slug}.{wildcard_base}（slug_status=active，含自动码 t-xxxxxx）
 *       > {app_domain}/{slug}/
 *       > {app_domain}/{tenant_id}/（兜底）
 *
 * 安全与体验约束：
 * - 仅 GET/HEAD；POST 等写操作不重定向
 * - API（/api/ 前缀）、XHR、接受 JSON 的请求不重定向（客户端不跟随 301 语义）
 * - 平台域名（admin/console/main）不参与收敛
 * - 当前入口即规范入口时直接放行（防循环）
 */
class EnforceCanonicalEntry
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldProcess($request)) {
            return $next($request);
        }

        $tenant = TenantContext::getTenant();
        if (! $tenant) {
            return $next($request);
        }

        $host = $request->header('X-Original-Host') ?? $request->getHost();
        $appDomain = config('domain.platform_domains.app');
        $wildcardBase = config('domain.wildcard_base');

        $slugActive = $tenant->slug && $tenant->slug_status === 'active';
        $customDomain = $this->approvedCustomDomain($tenant);

        // 解析当前入口形态，得到去掉租户前缀的剩余路径
        // 注意：app 域本身是 {base} 的子域名（如 app.neihang.com），必须先判 app 域再判通配
        $rest = null;
        if ($customDomain && $host === $customDomain) {
            $rest = $request->getPathInfo(); // 自定义域名：无前缀
        } elseif ($appDomain && $host === $appDomain) {
            $rest = $this->stripTenantPrefix($request, $tenant); // app 域路径前缀
        } elseif ($wildcardBase && $host !== $wildcardBase && str_ends_with($host, ".{$wildcardBase}")) {
            $rest = $request->getPathInfo(); // 二级域名（slug 或 tenant_id 形态）：无前缀
        }

        if ($rest === null) {
            return $next($request); // 非租户入口形态（平台域名等），不收敛
        }

        // 计算规范入口
        if ($customDomain) {
            $targetHost = $customDomain;
            $targetPrefix = '';
        } elseif ($slugActive && $wildcardBase) {
            $targetHost = "{$tenant->slug}.{$wildcardBase}";
            $targetPrefix = '';
        } elseif ($appDomain) {
            $targetHost = $appDomain;
            $targetPrefix = $slugActive ? "/{$tenant->slug}" : "/{$tenant->tenant_id}";
        } else {
            return $next($request);
        }

        // 已是规范入口 → 放行（防循环）
        if ($host === $targetHost && $this->currentPrefix($request, $host, $appDomain) === $targetPrefix) {
            return $next($request);
        }

        $target = $this->scheme($request) . '://' . $targetHost . $targetPrefix . $this->normalizeRest($rest);
        $query = $request->getQueryString();
        if ($query) {
            $target .= '?' . $query;
        }

        return new RedirectResponse($target, 301);
    }

    /**
     * 是否需要处理（跳过非页面请求与平台面）
     */
    protected function shouldProcess(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->ajax() || $request->expectsJson() || str_starts_with($request->getPathInfo(), '/api/')) {
            return false;
        }

        // 平台域名不参与收敛（admin/console/main；app 域是路径前缀载体，需要处理）
        $domainType = TenantContext::getDomainType();
        if (in_array($domainType, ['admin', 'console', 'default'], true)) {
            return false;
        }

        return true;
    }

    /**
     * 已审核通过的自定义域名（domain_status 存于 tenant_settings）
     */
    protected function approvedCustomDomain(Tenant $tenant): ?string
    {
        if (! $tenant->domain) {
            return null;
        }

        $status = TenantSetting::get(
            $tenant->tenant_id,
            DomainService::GROUP_DOMAIN,
            'domain_status',
            DomainService::STATUS_PENDING
        );

        return $status === DomainService::STATUS_APPROVED ? $tenant->domain : null;
    }

    /**
     * app 域：剥离第一段租户前缀；第一段不属于该租户时返回 null（不收敛，交由后续路由）
     */
    protected function stripTenantPrefix(Request $request, Tenant $tenant): ?string
    {
        $path = $request->getPathInfo();
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return null;
        }

        $first = explode('/', $trimmed)[0];

        if ($first !== (string) $tenant->tenant_id && $first !== $tenant->slug) {
            return null;
        }

        // 从原始路径剥掉首段，保留其余部分（含尾斜杠）
        $rest = substr($path, strlen('/' . $first));

        return $rest === '' ? '/' : $rest;
    }

    /**
     * 当前入口在 app 域上的前缀（/{slug} 或 /{tenant_id}）；域名形态无前缀
     */
    protected function currentPrefix(Request $request, string $host, ?string $appDomain): string
    {
        if (! $appDomain || $host !== $appDomain) {
            return '';
        }

        $first = explode('/', trim($request->getPathInfo(), '/'))[0] ?? '';

        return $first === '' ? '' : "/{$first}";
    }

    /**
     * 剩余路径规范化：保证以 / 开头；空路径收敛到 /h5/（租户前台首页）
     */
    protected function normalizeRest(string $rest): string
    {
        $rest = '/' . ltrim($rest, '/');

        return $rest === '/' ? '/h5/' : $rest;
    }

    /**
     * 目标 scheme：信任 SLB/代理的 X-Forwarded-Proto，否则沿用当前请求
     */
    protected function scheme(Request $request): string
    {
        $forwarded = $request->header('X-Forwarded-Proto');

        if ($forwarded === 'https' || $forwarded === 'http') {
            return $forwarded;
        }

        return $request->getScheme();
    }
}
