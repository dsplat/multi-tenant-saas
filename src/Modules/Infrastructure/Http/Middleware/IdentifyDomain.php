<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * 域名识别中间件
 *
 * 识别当前请求的域名类型：admin/console/api/app/default
 * - 平台域按 host 精确匹配（platform_domains：main/admin/console/api，env 注入）
 * - 单域名部署兼容：平台面路径声明（/admin、/console、/api）归对应类型
 * - app 为兜底类型（租户接入域名：自定义域名 / {slug}.{base} / {tenant_id}.{base}）
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

        // Console域名（共享管理后台，如 console.example.com）
        $consoleDomain = config('domain.platform_domains.console');
        if ($consoleDomain && $host === $consoleDomain) {
            return self::DOMAIN_CONSOLE;
        }

        // 平台主域（官网/首页，如 www.example.com）——非租户入口，不参与租户收敛
        $mainDomain = config('domain.platform_domains.main');
        if ($mainDomain && $host === $mainDomain) {
            return self::DOMAIN_DEFAULT;
        }

        // 独立 API 域（可选；未配置时 API 随各 SPA 域名提供）
        $apiDomain = config('domain.platform_domains.api');
        if ($apiDomain && $host === $apiDomain) {
            return self::DOMAIN_API;
        }

        // 路径区分
        if (str_starts_with($path, '/admin')) {
            return self::DOMAIN_ADMIN;
        }

        if (str_starts_with($path, '/console')) {
            return self::DOMAIN_CONSOLE;
        }

        if (str_starts_with($path, '/api')) {
            return self::DOMAIN_API;
        }

        // 其余（含全部租户接入域名）归为 app 类型
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
