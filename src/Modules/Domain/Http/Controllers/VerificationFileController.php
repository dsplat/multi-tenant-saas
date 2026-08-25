<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Domain\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 域名验证文件动态服务控制器
 *
 * 为租户域名提供两类验证文件（无需认证、无中间件链，微信/企微/支付宝
 * 验证服务器直接 GET 根路径文件）：
 *
 *  1. 平台归属验证：/.well-known/tenant-verify/{token}.txt → 内容为 token
 *  2. 第三方平台验证（微信 MP_verify_* / 企微 WW_verify_* / 支付宝 alipay_verify_*）：
 *     /{filename}.txt → 内容为去掉前缀后的验证码（企微规则：仅返回 WW_verify_ 之后部分，
 *     纯文本、无空格/换行/不可见字符；微信系同源规则一并适用，其他前缀返回去 .txt 全名）
 *
 * 设计要点：
 *  - 裸路由注册（不挂 web 组）：规避 EnforceCanonicalEntry 对 pending 自定义域名
 *    的 301 收敛——验证文件必须在「域名尚未 approved」时即可访问，收敛会致验证失败
 *  - 控制器内按真实 Host 手动解析租户（tenants.domain 精确匹配 → {slug}.{base} 兜底），
 *    与 IdentifyTenant 第 3/7 级同规则；不信任 X-Original-Host 等可伪造头
 *  - 平台统一回调域（OAUTH_CALLBACK_DOMAIN，如 auth.dsplat.com）：微信/企微验证的
 *    是回调域而非租户域名，该域无法解析租户 → 跨租户匹配文件名（微信下发的文件名
 *    全局唯一，内容即文件名本身，无敏感数据）
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
        $fullName = $file . '.txt';

        // 1. 租户域名（自定义域/通配子域）：按 Host 解析租户后查该租户的注册列表
        $tenant = $this->resolveTenantByHost($request);

        if ($tenant) {
            $files = TenantSetting::get(
                (int) $tenant->tenant_id,
                DomainService::GROUP_DOMAIN,
                DomainService::SETTING_THIRD_PARTY_VERIFY_FILES,
                []
            );

            if (is_array($files) && in_array($fullName, $files, true)) {
                return $this->plainText($this->fileContent($file));
            }

            abort(404);
        }

        // 2. 平台统一回调域（OAUTH_CALLBACK_DOMAIN）：微信/企微验证的是回调域，
        //    该域不属于任何租户 → 跨租户匹配文件名（文件名全局唯一）
        $host = strtolower((string) $request->getHost());
        $callbackDomain = strtolower((string) config('auth.oauth.callback_domain', ''));

        if ($callbackDomain !== '' && $host === $callbackDomain
            && $this->fileRegisteredAcrossTenants($fullName)) {
            return $this->plainText($this->fileContent($file));
        }

        abort(404);
    }

    /**
     * 验证文件响应内容：微信系（WW_verify_/MP_verify_）只返回前缀后的验证码，
     * 企微明确要求不得携带前缀且无任何空白字符；其余平台返回去 .txt 全名。
     */
    protected function fileContent(string $file): string
    {
        if (preg_match('/^(?:WW_verify|MP_verify)_(.+)$/', $file, $m) === 1) {
            return $m[1];
        }

        return $file;
    }

    /**
     * 跨租户匹配第三方验证文件名（仅平台回调域场景使用）
     *
     * 验证文件存于各租户 tenant_settings（domain 组 / third_party_verify_files，
     * JSON 数组）；微信下发的文件名全局唯一，命中任一租户即返回。
     */
    protected function fileRegisteredAcrossTenants(string $fullName): bool
    {
        return TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('group', DomainService::GROUP_DOMAIN)
            ->where('key', DomainService::SETTING_THIRD_PARTY_VERIFY_FILES)
            ->get()
            ->contains(fn ($setting) => is_array($setting->value) && in_array($fullName, $setting->value, true));
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
