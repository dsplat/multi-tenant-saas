<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session Cookie 域名动态绑定中间件
 *
 * 将 session.domain 严格设为当前请求的完整 host，
 * 确保不同租户自定义域名之间的 Cookie 完全隔离。
 *
 * 注册位置：IdentifyDomain 之后、IdentifyTenant 之前。
 */
class BindSessionDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->header('X-Original-Host') ?? $request->getHost();

        // 动态绑定 session cookie 到当前完整域名
        config(['session.domain' => $host]);

        // 生产环境强制安全属性
        if (app()->environment('production')) {
            config([
                'session.secure' => true,
                'session.same_site' => 'lax',
            ]);
        }

        return $next($request);
    }
}
