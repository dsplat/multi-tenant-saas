<?php

namespace MultiTenantSaas\Modules\Domain\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     */
    public function resolve(Request $request): JsonResponse
    {
        $domain = $request->query('domain');

        if (! $domain) {
            return response()->json([
                'success' => false,
                'message' => 'domain parameter is required',
            ], 422);
        }

        $tenant = Tenant::where('custom_domain', $domain)
            ->where('status', 'active')
            ->first(['tenant_id', 'name', 'slug', 'logo', 'branding', 'custom_domain']);

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
                'custom_domain' => $tenant->custom_domain,
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
     *
     * 返回该租户可用的登录方式、OAuth 提供商、注册开关等。
     */
    public function loginConfig(Request $request): JsonResponse
    {
        $domain = $request->query('domain');

        if (! $domain) {
            return response()->json([
                'success' => false,
                'message' => 'domain parameter is required',
            ], 422);
        }

        $tenant = Tenant::where('custom_domain', $domain)
            ->where('status', 'active')
            ->first(['tenant_id']);

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'tenant_not_found',
            ], 404);
        }

        $tenantId = $tenant->tenant_id;

        // 聚合 OAuth 提供商（Socialite 驱动）
        $oauthProviders = [];
        $oauthConfig = SocialiteService::getOAuthConfigForDisplay($tenantId);
        foreach ($oauthConfig as $provider => $config) {
            if ($config['configured']) {
                $oauthProviders[] = [
                    'provider' => $provider,
                    'name' => SocialiteService::getSupportedProviders()[$provider]['name'] ?? $provider,
                    'icon' => SocialiteService::getSupportedProviders()[$provider]['icon'] ?? $provider,
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
        $loginMethods = TenantSettingService::get($tenantId, 'sso', 'login_methods', ['email']);
        $allowRegister = (bool) TenantSettingService::get($tenantId, 'sso', 'allow_register', false);
        $emailDomainRestriction = TenantSettingService::get($tenantId, 'sso', 'email_domain_restriction');

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
}
