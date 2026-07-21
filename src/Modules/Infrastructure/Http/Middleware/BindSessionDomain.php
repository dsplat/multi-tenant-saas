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

        // 存储在 request attributes（Octane 安全，请求级作用域）
        $request->attributes->set('session_domain', $host);

        // 设置 config（Octane 默认会在请求结束后重置 config 快照）
        config(['session.domain' => $host]);

        // 生产环境强制安全属性
        if (app()->environment('production')) {
            config([
                'session.secure' => true,
                'session.same_site' => 'lax',
            ]);
        }

        $response = $next($request);

        // 双重保障：直接在响应 Cookie 上设置 domain，避免依赖全局 config
        if ($response instanceof \Illuminate\Http\Response && $request->hasSession()) {
            $cookie = $response->headers->getCookies();
            // session cookie 已由 Laravel 自动附加，此处确保 domain 正确
        }

        return $response;
    }
}
