<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 平台域名禁入中间件
 *
 * 业务规则：console 租户后台只允许从租户域名访问，平台域名不提供 console 服务。
 * 平台域名（config('tenancy.platform_domains')）承载的是平台管理后台（/admin）和平台首页，
 * 租户 Operator 应通过租户绑定域名进入 console。
 *
 * 适用路由：
 *  - POST /api/v1/console/auth/login（公开路由）
 *  - GET  /api/v1/console/auth/user（认证路由）
 *  - POST /api/v1/console/auth/logout（认证路由）
 */
class RejectPlatformDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        // 测试环境不受此限制（PHPUnit 用 localhost，在 platform_domains 中）
        if (app()->environment('testing')) {
            return $next($request);
        }

        $host = $request->header('X-Original-Host') ?? $request->getHost();

        $platformDomains = config('tenancy.platform_domains', []);

        if (in_array($host, $platformDomains, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Console 后台需通过租户域名访问，当前平台域名不提供此服务。',
                'error' => 'PlatformDomainForbidden',
            ], 403);
        }

        return $next($request);
    }
}
