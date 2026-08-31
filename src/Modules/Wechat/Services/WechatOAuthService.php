<?php

namespace MultiTenantSaas\Modules\Wechat\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Support\Wechat\WechatApiClient;

/**
 * 微信 OAuth 认证服务（Wechat 模块承载，从 Auth 模块迁入并扩展双轨）
 *
 * 微信网页授权与标准 OAuth2 有差异：
 * - 授权端点：https://open.weixin.qq.com/connect/oauth2/authorize
 * - 需要 scope 参数（snsapi_base 静默 / snsapi_userinfo 弹窗）
 * - access_token 通过 code + appid + secret 获取（自建）或
 *   code + component_appid + component_access_token 获取（第三方平台）
 * - 用户信息通过 access_token + openid 获取
 *
 * 凭证来源双轨（与企微 WechatWorkOAuthService 对齐）：
 * - 自建应用模式（mode=self）：租户手填凭证，存储于 tenant_settings group='oauth'
 *   （wechat_client_id / wechat_client_secret），PC 扫码/普通公众号网页授权
 * - 第三方平台模式（mode=component）：租户已授权平台为服务商（wechat_authorizations
 *   表 status=authorized），authorizer_appid 充当 appid，网页授权由第三方平台
 *   代替实现（公众号无需配置网页授权域名），回调域用平台统一回调域；
 *   换 token 走 sns/oauth2/component/access_token
 *
 * 双轨按租户有无授权记录自动切换：授权后 H5 公众号网页授权走 component；
 * 未授权租户 PC 扫码登录走 self（开放平台网站应用），两者并存互补。
 */
class WechatOAuthService
{
    use ManagesOAuthState;

    /**
     * 微信 API 基础地址
     */
    protected const API_BASE = 'https://api.weixin.qq.com/sns';

    /**
     * 授权页地址
     */
    protected const AUTHORIZE_URL = 'https://open.weixin.qq.com/connect/oauth2/authorize';

    /**
     * 获取租户微信配置
     *
     * 凭证来源双轨：租户已完成第三方平台授权（wechat_authorizations
     * status=authorized）时优先走 component 模式（authorizer_appid 充当
     * appid，回调域用平台统一回调域）；否则走自建应用模式
     * （mode=self，oauth 组手填凭证）。
     *
     * @throws \RuntimeException 当两种模式均未配置
     */
    protected function getConfig(int $tenantId): array
    {
        $authorization = $this->componentAuthorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            $callbackDomain = config('auth.oauth.callback_domain', '');
            $redirect = $callbackDomain !== ''
                ? "https://{$callbackDomain}/api/v1/auth/wechat/callback"
                : $this->resolveWechatRedirectUrl($tenantId, '');

            return [
                'app_id' => $authorization->authorizer_appid,
                'secret' => '',
                'redirect' => $redirect,
                'mode' => 'component',
            ];
        }

        $appId = TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', '');

        if (empty($appId) || empty($secret)) {
            throw new ServiceUnavailableException(trans('common.oauth_not_configured', ['provider' => 'wechat', 'tenant' => $tenantId]));
        }

        return [
            'app_id' => $appId,
            'secret' => $secret,
            'redirect' => $this->resolveWechatRedirectUrl($tenantId, TenantSetting::get($tenantId, 'oauth', 'wechat_redirect', '')),
            'mode' => 'self',
        ];
    }

    /**
     * 解析微信回调完整 URL（与 SocialiteService::resolveRedirectUrl 同步实现）
     *
     * 9.6 迁移后 Wechat 模块自包含，消除对 Auth 模块的辅助方法依赖；
     * 逻辑保持与 SocialiteService::resolveRedirectUrl 一致，后续演化需同步。
     *
     * 优先级：
     * 1. 租户显式存储的完整 URL（自选回调地址，最高）
     * 2. 租户自定义域名（tenants.domain）：自建模式回调域要求备案主体与
     *    公众号主体一致，平台统一回调域过不了主体校验
     * 3. 平台统一回调域（OAUTH_CALLBACK_DOMAIN）：仅无自定义域名的租户使用
     * 4. 相对路径兜底（平台域场景）
     */
    protected function resolveWechatRedirectUrl(int $tenantId, string $storedRedirect): string
    {
        // 已存储完整 URL（显式覆盖）
        if ($storedRedirect && str_starts_with($storedRedirect, 'http')) {
            return $storedRedirect;
        }

        // 租户自定义域名优先（主体校验要求域名归租户企业所有）
        $domain = Tenant::where('tenant_id', $tenantId)->value('domain');
        if ($domain) {
            return "https://{$domain}/api/v1/auth/wechat/callback";
        }

        // 无自定义域名 → 平台统一回调域（平台级虚拟 IDP）
        $callbackDomain = config('auth.oauth.callback_domain', '');
        if ($callbackDomain !== '') {
            return "https://{$callbackDomain}/api/v1/auth/wechat/callback";
        }

        return $storedRedirect ?: '/api/v1/auth/wechat/callback';
    }

    /**
     * 读取第三方平台授权记录（双轨查询入口）
     *
     * Wechat 模块为可选拆包（dsplat/multi-tenant-saas-module-wechat），
     * 下游未安装或未迁移时类/表缺失：返回 null 回退自建应用模式，不得抛 SQL 错误。
     */
    protected function componentAuthorization(int $tenantId)
    {
        if (! class_exists(WechatComponentService::class)) {
            return null;
        }

        if (! Schema::hasTable('wechat_authorizations')) {
            return null;
        }

        return app(WechatComponentService::class)->authorization($tenantId);
    }

    /**
     * 生成授权跳转 URL（网页授权页）
     *
     * component 模式下 appid 为已授权公众号 authorizer_appid，授权页由
     * 第三方平台代替实现（公众号无需配置网页授权域名），URL 结构与自建一致。
     *
     * @param  string  $originDomain  用户来源域名（回调后回跳）
     */
    public function getAuthorizeUrl(int $tenantId, string $originDomain = ''): string
    {
        $config = $this->getConfig($tenantId);

        $state = $this->generateState($tenantId, 'wechat', ['origin_domain' => $originDomain]);

        $params = [
            'appid' => $config['app_id'],
            'redirect_uri' => $config['redirect'],
            'response_type' => 'code',
            'scope' => 'snsapi_userinfo',
            'state' => $state,
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($params) . '#wechat_redirect';
    }

    /**
     * 处理 OAuth 回调，返回用户信息 + token
     *
     * component 模式换 token 走 sns/oauth2/component/access_token
     * （code + component_appid + component_access_token，无 secret）；
     * self 模式走 sns/oauth2/access_token（code + appid + secret）。
     * 绑定/注册/签发链路两模式共用。
     */
    public function handleCallback(int $tenantId): array
    {
        $code = (string) request()->input('code', '');
        $state = (string) request()->input('state', '');

        $context = $this->verifyState($state, $tenantId, 'wechat');

        if ($code === '') {
            throw new DomainException(trans('common.invalid_request'));
        }

        $config = $this->getConfig($tenantId);

        // 通过 code 换取 access_token + openid + unionid
        $tokenData = ($config['mode'] ?? '') === 'component'
            ? $this->getComponentAccessToken($config, $code)
            : $this->getAccessToken($config, $code);
        $accessToken = $tokenData['access_token'];
        $openId = $tokenData['openid'];
        $unionid = $tokenData['unionid'] ?? '';

        // 获取用户信息（sns/userinfo，两模式同构）
        $userInfo = $this->getUserInfo($accessToken, $openId);

        $user = $this->findOrCreateUser($userInfo, $openId, $tenantId, $unionid);
        $this->recordOAuthAccount($user, $userInfo, $openId, $accessToken, $tenantId, $unionid, $config['app_id']);

        return [
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $user->createToken('wechat-login')->plainTextToken,
            'origin_domain' => $context['origin_domain'] ?? '',
        ];
    }

    /**
     * 通过 code 换取 access_token（自建模式：appid + secret）
     */
    protected function getAccessToken(array $config, string $code): array
    {
        $resp = Http::get(self::API_BASE . '/oauth2/access_token', [
            'appid' => $config['app_id'],
            'secret' => $config['secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        return $this->parseResponse($resp, 'oauth2/access_token');
    }

    /**
     * 通过 code 换取 access_token（component 模式：component_appid + component_access_token）
     *
     * 公众号授权给第三方平台后，网页授权由第三方平台代替实现；换取结果
     * 与自建模式同构（openid/unionid）。component_access_token 由
     * WechatComponentService 统一缓存管理（提前 300s 过期）。
     */
    protected function getComponentAccessToken(array $config, string $code): array
    {
        $componentService = app(WechatComponentService::class);
        $provider = $componentService->requireProvider();

        $client = new WechatApiClient(
            appId: $config['app_id'],
            componentTokenResolver: fn () => $componentService->componentAccessToken($provider),
            componentAppId: $provider->component_appid,
        );

        $data = $client->getUserByCode($code);

        if (empty($data['access_token'])) {
            throw new ServiceUnavailableException('Wechat API error: component access_token exchange failed');
        }

        return $data;
    }

    /**
     * 获取用户信息
     */
    protected function getUserInfo(string $accessToken, string $openId): array
    {
        $resp = Http::get(self::API_BASE . '/userinfo', [
            'access_token' => $accessToken,
            'openid' => $openId,
            'lang' => 'zh_CN',
        ]);

        return $this->parseResponse($resp, 'userinfo');
    }

    /**
     * 解析微信 API 响应
     */
    protected function parseResponse($resp, string $api): array
    {
        if (! $resp->successful()) {
            Log::error('[WechatOAuthService] HTTP failed', [
                'api' => $api,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            throw new ServiceUnavailableException("Wechat API request failed: HTTP {$resp->status()}");
        }

        $data = $resp->json();
        $errCode = $data['errcode'] ?? 0;

        if ($errCode !== 0) {
            $errMsg = $data['errmsg'] ?? 'unknown error';
            Log::error('[WechatOAuthService] API error', [
                'api' => $api,
                'errcode' => $errCode,
                'errmsg' => $errMsg,
            ]);
            throw new ServiceUnavailableException("Wechat API error [{$errCode}]: {$errMsg}");
        }

        return $data;
    }

    /**
     * 生成命名空间化的 provider 标识（原 SocialiteService 辅助方法内联，
     * 9.6 迁移后 Wechat 模块自包含，消除模块反向依赖）
     *
     * 格式: wechat:tenant:{tenantId}
     * 确保同一 OAuth 应用在不同租户间隔离
     */
    protected function namespacedProvider(int $tenantId): string
    {
        return "wechat:tenant:{$tenantId}";
    }

    /**
     * 查找或创建用户
     */
    public function findOrCreateUser(array $wxUser, string $openId, int $tenantId, string $unionid = ''): User
    {
        $nsProvider = $this->namespacedProvider($tenantId);

        // 1. 优先通过 unionid 查找（跨应用唯一）
        if ($unionid !== '') {
            $byUnionid = OauthAccount::where('unionid', $unionid)
                ->where('provider', 'like', 'wechat%')
                ->first();
            if ($byUnionid && $byUnionid->user) {
                $this->ensureTenantUser($byUnionid->user, $tenantId);

                return $byUnionid->user;
            }
        }

        // 2. 通过 openid + provider 查找
        $oauthAccount = OauthAccount::where('provider', $nsProvider)
            ->where('provider_id', $openId)
            ->first();

        if ($oauthAccount) {
            $existingUser = $oauthAccount->user;
            $this->ensureTenantUser($existingUser, $tenantId);

            return $existingUser;
        }

        // 微信不返回邮箱，使用 openid 作为唯一标识
        $email = $openId . '@wechat';

        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $wxUser['nickname'] ?? ('wx_' . Str::limit($openId, 8)),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'avatar' => $wxUser['headimgurl'] ?? null,
            ]);

            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        return $user;
    }

    /**
     * 记录 OAuth 账号（含 unionid/openid/appid 冗余）
     */
    protected function recordOAuthAccount(User $user, array $userInfo, string $openId, string $accessToken, int $tenantId, string $unionid = '', string $appid = ''): void
    {
        $nsProvider = $this->namespacedProvider($tenantId);

        OauthAccount::updateOrCreate(
            [
                'user_id' => $user->user_id,
                'provider' => $nsProvider,
                'provider_id' => $openId,
            ],
            [
                'tenant_id' => $tenantId,
                'unionid' => $unionid ?: null,
                'openid' => $openId,
                'appid' => $appid ?: null,
                'provider_email' => null,
                'provider_name' => $userInfo['nickname'] ?? null,
                'provider_avatar' => $userInfo['headimgurl'] ?? null,
                'access_token' => encrypt($accessToken),
                'token_expires_at' => now()->addSeconds(7200),
            ]
        );
    }

    /**
     * 确保用户关联到租户
     */
    protected function ensureTenantUser(User $user, int $tenantId): void
    {
        $exists = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->user_id)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }
    }

    /**
     * 检查租户是否已配置微信 OAuth（第三方平台授权或自建应用任一满足）
     */
    public function isConfigured(int $tenantId): bool
    {
        $authorization = $this->componentAuthorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            return true;
        }

        $appId = TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', '');

        return ! empty($appId) && ! empty($secret);
    }
}
