<?php

namespace MultiTenantSaas\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * 域名识别中间件
 *
 * 识别当前请求的域名类型：admin/console/api/app
 */
class IdentifyDomain
{
    public const DOMAIN_ADMIN = 'admin';

    public const DOMAIN_CONSOLE = 'console';

    public const DOMAIN_API = 'api';

    public const DOMAIN_APP = 'app';

    public const DOMAIN_DEFAULT = 'default';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->header('X-Original-Host') ?? $request->getHost();
        $domainType = $this->identifyDomainType($host, $request->getPathInfo());

        TenantContext::setDomainType($domainType);

        return $next($request);
    }

    /**
     * 识别域名类型
     */
    protected function identifyDomainType(string $host, string $path = '/'): string
    {
        // 测试环境
        if (app()->environment('testing') && $host === 'localhost') {
            if (str_starts_with($path, '/api')) {
                return self::DOMAIN_API;
            }
            if (str_starts_with($path, '/console')) {
                return self::DOMAIN_CONSOLE;
            }

            return self::DOMAIN_DEFAULT;
        }

        // Admin域名
        $adminDomain = config('app.admin_domain') ?? config('tenancy.admin_domain');
        if ($adminDomain && $host === $adminDomain) {
            return self::DOMAIN_ADMIN;
        }

        // 路径区分
        if (str_starts_with($path, '/console')) {
            return self::DOMAIN_CONSOLE;
        }

        if (str_starts_with($path, '/api')) {
            return self::DOMAIN_API;
        }

        return self::DOMAIN_APP;
    }

    /**
     * 获取当前域名类型
     */
    public static function getCurrentDomainType(Request $request): string
    {
        return TenantContext::getDomainType() ?? self::DOMAIN_DEFAULT;
    }

    /**
     * 判断是否为管理后台域名
     */
    public static function isAdminDomain(Request $request): bool
    {
        return self::getCurrentDomainType($request) === self::DOMAIN_ADMIN;
    }
}
