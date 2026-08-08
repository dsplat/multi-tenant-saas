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
 * 一个租户存在最多三种入口（自定义域名 / slug 二级域名 / tenant_id 二级域名），
 * 全部可解析，但规范入口唯一，其余 301 收敛（docs/tenant.md §2.0）：
 *
 *   canonical(tenant) =
 *       自定义域名（domain 非空 且 domain_status=approved）
 *       > {slug}.{wildcard_base}（slug_status=active，含自动码 t-xxxxxx）
 *       > {tenant_id}.{wildcard_base}（兜底）
 *
 * 架构约束：不支持 app 域路径前缀（/{slug}/、/{tenant_id}/）形态，
 * 租户共享入口一律为子域名，与 nginx 统一基桩白名单同构。
 *
 * 守护面判定（不依赖路径前缀，路由入口文件天然隔离）：
 * - 本中间件仅注册于 web 组；API 路由走 api 组（routes/api.php + 模块 api/v1），
 *   结构性不会到达本中间件，无需路径判断
 * - 仅 GET/HEAD；POST 等写操作不重定向
 * - XHR、接受 JSON 的请求不重定向（客户端不跟随 301 语义）
 * - 只守护租户入口面（域类型 app）；平台面/API 面由 IdentifyDomain 域类型排除，不看路径
 * - 路径原样保留不改写；落地页跳转是项目入口层的事（如 nginx location = / 302）
 * - 当前入口即规范入口时直接放行（防循环）
 * - 未配置 wildcard_base 且无 approved 自定义域名时无规范入口，直接放行
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
        $wildcardBase = config('domain.wildcard_base');

        $slugActive = $tenant->slug && $tenant->slug_status === 'active';
        $customDomain = $this->approvedCustomDomain($tenant);

        // 解析当前入口形态：仅自定义域名与 {base} 子域名两种租户入口
        $isTenantEntry = ($customDomain && $host === $customDomain)
            || ($wildcardBase && $host !== $wildcardBase && str_ends_with($host, ".{$wildcardBase}"));

        if (! $isTenantEntry) {
            return $next($request); // 非租户入口形态（平台域名等），不收敛
        }

        // 计算规范入口
        if ($customDomain) {
            $targetHost = $customDomain;
        } elseif ($slugActive && $wildcardBase) {
            $targetHost = "{$tenant->slug}.{$wildcardBase}";
        } elseif ($wildcardBase) {
            $targetHost = "{$tenant->tenant_id}.{$wildcardBase}";
        } else {
            return $next($request); // 无规范入口（未配通配 base 且无自定义域名）
        }

        // 已是规范入口 → 放行（防循环）
        if ($host === $targetHost) {
            return $next($request);
        }

        $target = $this->scheme($request) . '://' . $targetHost . $request->getPathInfo();
        $query = $request->getQueryString();
        if ($query) {
            $target .= '?' . $query;
        }

        return new RedirectResponse($target, 301);
    }

    /**
     * 是否需要处理（跳过非页面请求与平台面）
     *
     * 注意：API 请求天然不会到达本中间件（api 组与 web 组路由入口隔离），
     * 此处不做任何路径前缀判断；平台面按 IdentifyDomain 给出的域类型识别。
     */
    protected function shouldProcess(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->ajax() || $request->expectsJson()) {
            return false;
        }

        // 只守护租户入口面（域类型判定，非路径判定；平台面/API 面均排除）
        if (TenantContext::getDomainType() !== IdentifyDomain::DOMAIN_APP) {
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
