<?php

namespace MultiTenantSaas\Modules\Domain\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Auth\Services\SsoService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;

/**
 * 租户发现公开控制器
 *
 * 无需认证，供前端登录页在用户认证前获取租户信息和登录配置。
 */
class TenantResolveController extends Controller
{
    /**
     * 按域名解析租户
     *
     * GET /api/v1/tenant/resolve?domain={host}
     * domain 参数可选：无参数时从请求 Host 自动解析（IdentifyTenant 中间件已设置 TenantContext）
     */
    public function resolve(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'tenant_not_found',
            ], 404);
        }

        $branding = $tenant->branding ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logo' => $tenant->logo,
                'domain' => $tenant->domain,
                'branding' => [
                    'primary_color' => $branding['primary_color'] ?? null,
                    'secondary_color' => $branding['secondary_color'] ?? null,
                    'login_page_message' => $branding['login_page_message'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * 获取租户登录配置
     *
     * GET /api/v1/tenant/login-config?domain={host}
     * domain 参数可选：无参数时从请求 Host 自动解析
     */
    public function loginConfig(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'tenant_not_found',
            ], 404);
        }

        $tenantId = $tenant->tenant_id;

        // 聚合 OAuth 提供商（Socialite 驱动）
        $oauthProviders = [];
        $oauthConfig = app(SocialiteService::class)->getOAuthConfigForDisplay($tenantId);
        foreach ($oauthConfig as $provider => $config) {
            if ($config['configured']) {
                $oauthProviders[] = [
                    'provider' => $provider,
                    'name' => app(SocialiteService::class)->getSupportedProviders()[$provider]['name'] ?? $provider,
                    'icon' => app(SocialiteService::class)->getSupportedProviders()[$provider]['icon'] ?? $provider,
                ];
            }
        }

        // 聚合 SSO 提供商（SAML/OIDC）
        $ssoProviders = [];
        $ssoService = app(SsoService::class);
        foreach ($ssoService->listProviders($tenantId) as $ssoProvider) {
            if ($ssoProvider->status === 'active') {
                $ssoProviders[] = [
                    'provider' => "sso:{$ssoProvider->name}",
                    'name' => $ssoProvider->display_name ?? $ssoProvider->name,
                    'type' => $ssoProvider->type,
                ];
            }
        }

        // 登录方式配置
        $loginMethods = app(TenantSettingService::class)->get($tenantId, 'sso', 'login_methods', ['email']);
        $allowRegister = (bool) app(TenantSettingService::class)->get($tenantId, 'sso', 'allow_register', false);
        $emailDomainRestriction = app(TenantSettingService::class)->get($tenantId, 'sso', 'email_domain_restriction');

        return response()->json([
            'success' => true,
            'data' => [
                'login_methods' => $loginMethods,
                'oauth_providers' => $oauthProviders,
                'sso_providers' => $ssoProviders,
                'allow_register' => $allowRegister,
                'email_domain_restriction' => $emailDomainRestriction,
            ],
        ]);
    }

    /**
     * 解析租户（domain 参数 > TenantContext > 请求 Host）
     */
    protected function resolveTenant(Request $request): ?Tenant
    {
        // 1. 显式 domain 参数
        if ($domain = $request->query('domain')) {
            return Tenant::where('domain', $domain)
                ->where('status', 'active')
                ->first(['tenant_id', 'name', 'slug', 'logo', 'branding', 'domain']);
        }

        // 2. TenantContext（IdentifyTenant 中间件已从请求 Host 解析）
        if ($tenantId = TenantContext::getId()) {
            return Tenant::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->first(['tenant_id', 'name', 'slug', 'logo', 'branding', 'domain']);
        }

        // 3. 直接从请求 Host 查找
        $host = $request->header('X-Original-Host') ?? $request->getHost();

        return Tenant::where('domain', $host)
            ->where('status', 'active')
            ->first(['tenant_id', 'name', 'slug', 'logo', 'branding', 'domain']);
    }
}
