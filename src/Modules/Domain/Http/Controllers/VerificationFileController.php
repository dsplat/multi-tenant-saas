<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Domain\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 域名验证文件动态服务控制器
 *
 * 为租户域名提供两类验证文件（无需认证、无中间件链，微信/企微/支付宝
 * 验证服务器直接 GET 根路径文件）：
 *
 *  1. 平台归属验证：/.well-known/tenant-verify/{token}.txt → 内容为 token
 *  2. 第三方平台验证（微信 MP_verify_* / 企微 WW_verify_* / 支付宝 alipay_verify_*）：
 *     /{filename}.txt → 内容为去掉 .txt 的文件名（三大平台统一规则）
 *
 * 设计要点：
 *  - 裸路由注册（不挂 web 组）：规避 EnforceCanonicalEntry 对 pending 自定义域名
 *    的 301 收敛——验证文件必须在「域名尚未 approved」时即可访问，收敛会致验证失败
 *  - 控制器内按真实 Host 手动解析租户（tenants.domain 精确匹配 → {slug}.{base} 兜底），
 *    与 IdentifyTenant 第 3/7 级同规则；不信任 X-Original-Host 等可伪造头
 *  - 未命中一律 404（不区分「无此租户/无此文件」，避免探测）
 */
class VerificationFileController
{
    public function token(Request $request, string $token): Response
    {
        $tenant = $this->resolveTenantByHost($request);

        if (! $tenant) {
            abort(404);
        }

        $expected = TenantSetting::get(
            (int) $tenant->tenant_id,
            DomainService::GROUP_DOMAIN,
            'verification_token'
        );

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
        }

        return $this->plainText($token);
    }

    public function file(Request $request, string $file): Response
    {
        $tenant = $this->resolveTenantByHost($request);

        if (! $tenant) {
            abort(404);
        }

        $files = TenantSetting::get(
            (int) $tenant->tenant_id,
            DomainService::GROUP_DOMAIN,
            DomainService::SETTING_THIRD_PARTY_VERIFY_FILES,
            []
        );

        $fullName = $file . '.txt';

        if (! is_array($files) || ! in_array($fullName, $files, true)) {
            abort(404);
        }

        return $this->plainText($file);
    }

    /**
     * 按请求 Host 解析租户（与 IdentifyTenant 自定义域名/通配子域同规则）
     */
    protected function resolveTenantByHost(Request $request): ?Tenant
    {
        $host = strtolower((string) $request->getHost());

        if ($host === '') {
            return null;
        }

        // 1. 自定义域名精确匹配（域名即归属证明）
        $tenant = Tenant::where('domain', $host)->first();
        if ($tenant) {
            return $tenant;
        }

        // 2. {slug}.{wildcard_base} 通配子域兜底（slug 须 active）
        $wildcardBase = (string) config('domain.wildcard_base', '');
        if ($wildcardBase !== '' && str_ends_with($host, ".{$wildcardBase}")) {
            $slug = substr($host, 0, -(strlen($wildcardBase) + 1));
            if ($slug !== '') {
                return Tenant::where('slug', $slug)
                    ->where('slug_status', 'active')
                    ->first();
            }
        }

        return null;
    }

    protected function plainText(string $content): Response
    {
        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
