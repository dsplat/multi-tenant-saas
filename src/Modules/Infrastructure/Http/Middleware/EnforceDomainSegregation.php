<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * 域名分工隔离中间件（全局「门」）
 *
 * 域名配置全部来自 .env（PLATFORM_MAIN_DOMAIN / ADMIN_DOMAIN / PLATFORM_CONSOLE_DOMAIN
 * / PLATFORM_API_DOMAIN），本中间件只负责按域名排除互串：
 *
 *  - admin 域名：平台后台专用，不提供租户服务（/console、console API）
 *  - app 域名（用户终端/SEO 内容面）：不提供租户后台（/console、/app、console API）；
 *    app 域仅承载 SEO 直出内容（/{slug}/{type}-{id}.html），路径前缀形态不做租户交互
 *  - 非 admin 域名：不提供平台后台（/admin、admin API）
 *  - 平台主域（DOMAIN_DEFAULT）：不提供租户面（/console、/app）；
 *    页面请求 301 收敛到 console 专属域，API 请求 403；
 *    未配置 console 专属域（单域名部署）时不拦截
 *
 * 租户自定义域名 / t-xxxxxx 通配子域名 / {tenant_id}.{domain} 等租户接入域名
 * （域类型 app）访问 /console 属正常链路，一律放行。
 *
 * 本地开发（localhost/127.0.0.1）不受限；测试可用 X-Original-Host 注入模拟域名。
 */
class EnforceDomainSegregation
{
    /**
     * 本地开发域名（不受隔离限制）
     */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    public function handle(Request $request, Closure $next): Response
    {
        $originalHost = $request->header('X-Original-Host');
        $host = $originalHost ?? $request->getHost();

        // 本地开发放行；显式注入 X-Original-Host 时按注入域名正常判定（供测试/反代使用）
        if ($originalHost === null && in_array($host, self::LOCAL_HOSTS, true)) {
            return $next($request);
        }

        $adminDomain = config('tenancy.admin_domain');
        $path = $request->getPathInfo();
        $isAdminHost = $adminDomain && hash_equals($adminDomain, $host);

        // admin 域名不提供租户服务（console 后台 / app 前台 / console API）
        if ($isAdminHost && $this->isTenantSurface($path)) {
            return $this->forbidden($request, '平台管理域名不提供租户服务，请通过租户域名访问。');
        }

        // app 域名（用户终端/SEO 内容面）不提供租户后台：
        // app 裸域与 app/{slug}/ 路径均拒绝 console SPA 与 console API（直接 403，不收敛）；
        // app 域仅承载 SEO 直出内容路径（/ {slug}/{type}-{id}.html，不在租户面清单内）。
        $appDomain = config('domain.platform_domains.app');
        if ($appDomain && hash_equals($appDomain, $host) && $this->isTenantSurface($path)) {
            return $this->forbidden($request, '用户终端域不提供租户后台，请通过租户域名访问。');
        }

        // 平台主域不提供租户面：页面 301 收敛到 console 专属域，API 拒绝；
        // 域类型由 IdentifyDomain 正向判定（租户接入域为 app，不受影响）；
        // 未配置 console 专属域（单域名部署）时不拦截
        if (
            config('domain.platform_domains.console')
            && TenantContext::getDomainType() === IdentifyDomain::DOMAIN_DEFAULT
            && $this->isTenantSurface($path)
        ) {
            return $this->tenantSurfaceAway($request);
        }

        // 非 admin 域名不提供平台后台（admin SPA / admin API）
        if (! $isAdminHost && $this->isAdminSurface($path)) {
            return $this->forbidden($request, '平台后台仅通过管理域名访问。');
        }

        return $next($request);
    }

    /**
     * 租户面：console 后台 / app 前台 / console API
     *
     * 含 app 域路径形态（/{slug}/console、/{slug}/api/v1/console）：app 域纯 SEO
     * 内容面，不提供租户后台，路径第一段为 slug 的 console 形态同样直接拒绝。
     */
    protected function isTenantSurface(string $path): bool
    {
        return str_starts_with($path, '/console')
            || str_starts_with($path, '/app')
            || str_starts_with($path, '/api/v1/console')
            // app 域路径形态：/{slug}/console 与 /{slug}/api/v1/console
            || (bool) preg_match('#^/[^/]+/(?:console|api/v1/console)(?:/|$)#', $path);
    }

    /**
     * 平台后台面：admin SPA / admin API
     */
    protected function isAdminSurface(string $path): bool
    {
        return str_starts_with($path, '/admin')
            || str_starts_with($path, '/api/v1/admin');
    }

    protected function forbidden(Request $request, string $message): Response
    {
        if ($request->expectsJson() || str_starts_with($request->getPathInfo(), '/api/')) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => 'DomainSegregationForbidden',
            ], 403);
        }

        return response($message, 403)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * 平台主域上的租户面请求：页面 301 到 console 专属域（保留路径与 query），API 与非 GET 拒绝
     */
    protected function tenantSurfaceAway(Request $request): Response
    {
        if ($request->expectsJson() || str_starts_with($request->getPathInfo(), '/api/') || ! $request->isMethod('GET')) {
            return $this->forbidden($request, '平台主域不提供租户服务，请通过租户后台域名访问。');
        }

        $target = 'https://' . config('domain.platform_domains.console') . $request->getPathInfo();
        if ($query = $request->getQueryString()) {
            $target .= '?' . $query;
        }

        return new RedirectResponse($target, 301);
    }
}
