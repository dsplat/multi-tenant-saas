<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 域名分工隔离中间件（全局「门」）
 *
 * 域名配置全部来自 .env（PLATFORM_MAIN_DOMAIN / ADMIN_DOMAIN / PLATFORM_CONSOLE_DOMAIN
 * / PLATFORM_APP_DOMAIN / PLATFORM_API_DOMAIN），本中间件只负责按域名排除互串：
 *
 *  - admin 域名：平台后台专用，不提供租户服务（/console、/app、console API）
 *  - 非 admin 域名：不提供平台后台（/admin、admin API）
 *
 * 租户自定义域名 / t-xxxxxx 通配子域名 / {tenant_id}.{domain} 等租户接入域名
 * 访问 /console、/app 属正常链路，一律放行。
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

        // 非 admin 域名不提供平台后台（admin SPA / admin API）
        if (! $isAdminHost && $this->isAdminSurface($path)) {
            return $this->forbidden($request, '平台后台仅通过管理域名访问。');
        }

        return $next($request);
    }

    /**
     * 租户面：console 后台 / app 前台 / console API
     */
    protected function isTenantSurface(string $path): bool
    {
        return str_starts_with($path, '/console')
            || str_starts_with($path, '/app')
            || str_starts_with($path, '/api/v1/console');
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
}
