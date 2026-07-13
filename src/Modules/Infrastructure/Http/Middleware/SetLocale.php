<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 根据请求头 Accept-Language 设置应用语言环境
 */
class SetLocale
{
    private const SUPPORTED = ['zh_CN', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);
        app()->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        // 1. 优先从查询参数获取
        $query = $request->query('lang');
        if ($query && in_array($query, self::SUPPORTED)) {
            return $query;
        }

        // 2. 从 Header 获取
        $header = $request->header('Accept-Language');
        if ($header) {
            $primary = strtolower(explode(',', $header)[0]);
            $primary = str_replace('-', '_', $primary);

            if (in_array($primary, self::SUPPORTED)) {
                return $primary;
            }

            // 兼容 zh-CN / zh-cn 等
            if (str_starts_with($primary, 'zh')) {
                return 'zh_CN';
            }
            if (str_starts_with($primary, 'en')) {
                return 'en';
            }
        }

        // 3. 回退到配置默认值
        return config('app.locale', 'zh_CN');
    }
}
