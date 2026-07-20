<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

/**
 * 微信网页授权 OAuth 服务
 *
 * 微信网页授权与标准 OAuth2 有差异：
 * - 授权端点：https://open.weixin.qq.com/connect/oauth2/authorize
 * - 需要 scope 参数（snsapi_base 静默 / snsapi_userinfo 弹窗）
 * - access_token 通过 code + appid + secret 获取
 * - 用户信息通过 access_token + openid 获取
 *
 * 租户级配置（存储在 tenant_settings, group='oauth'）：
 *  - wechat_client_id     AppID（不加密）
 *  - wechat_client_secret AppSecret（加密）
 *  - wechat_redirect      回调 URL（不加密）
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
     * @throws \RuntimeException 当 client_id 或 client_secret 未配置
     */
    protected function getConfig(int $tenantId): array
    {
        $appId = TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', '');

        if (empty($appId) || empty($secret)) {
            throw new \RuntimeException(trans('common.oauth_not_configured', ['provider' => 'wechat', 'tenant' => $tenantId]));
        }

        return [
            'app_id' => $appId,
            'secret' => $secret,
            'redirect' => TenantSetting::get($tenantId, 'oauth', 'wechat_redirect', '/auth/wechat/callback'),
        ];
    }

    /**
     * 生成授权跳转 URL
     */
    public function getAuthorizeUrl(int $tenantId): string
    {
        $config = $this->getConfig($tenantId);

        $state = $this->generateState($tenantId, 'wechat');

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
     */
    public function handleCallback(int $tenantId): array
    {
        $code = (string) request()->input('code', '');
        $state = (string) request()->input('state', '');

        $this->verifyState($state, $tenantId, 'wechat');

        if ($code === '') {
            throw new \RuntimeException(trans('common.invalid_request'));
        }

        $config = $this->getConfig($tenantId);

        // 通过 code 换取 access_token + openid
        $tokenData = $this->getAccessToken($config, $code);
        $accessToken = $tokenData['access_token'];
        $openId = $tokenData['openid'];

        // 获取用户信息
        $userInfo = $this->getUserInfo($accessToken, $openId);

        $user = $this->findOrCreateUser($userInfo, $openId, $tenantId);
        $this->recordOAuthAccount($user, $userInfo, $openId, $accessToken, $tenantId);

        return [
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $user->createToken('wechat-login')->plainTextToken,
        ];
    }

    /**
     * 通过 code 换取 access_token
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
            throw new \RuntimeException("Wechat API request failed: HTTP {$resp->status()}");
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
            throw new \RuntimeException("Wechat API error [{$errCode}]: {$errMsg}");
        }

        return $data;
    }

    /**
     * 查找或创建用户
     */
    public function findOrCreateUser(array $wxUser, string $openId, int $tenantId): User
    {
        $nsProvider = SocialiteService::namespacedProvider('wechat', $tenantId);

        $oauthAccount = OauthAccount::where('provider', $nsProvider)
            ->where('provider_id', $openId)
            ->first();

        if ($oauthAccount) {
            $existingUser = $oauthAccount->user;

            $isMember = TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $existingUser->user_id)
                ->where('is_active', true)
                ->exists();

            if (! $isMember) {
                TenantUser::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $existingUser->user_id,
                    'role' => 'end_user',
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }

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
                'role' => 'platform_user',
            ]);

            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role' => 'end_user',
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        return $user;
    }

    /**
     * 记录 OAuth 账号
     */
    protected function recordOAuthAccount(User $user, array $userInfo, string $openId, string $accessToken, int $tenantId): void
    {
        $nsProvider = SocialiteService::namespacedProvider('wechat', $tenantId);

        OauthAccount::updateOrCreate(
            [
                'user_id' => $user->user_id,
                'provider' => $nsProvider,
                'provider_id' => $openId,
            ],
            [
                'tenant_id' => $tenantId,
                'provider_email' => null,
                'provider_name' => $userInfo['nickname'] ?? null,
                'provider_avatar' => $userInfo['headimgurl'] ?? null,
                'access_token' => encrypt($accessToken),
                'token_expires_at' => now()->addSeconds(7200),
            ]
        );
    }

    /**
     * 检查租户是否已配置微信 OAuth
     */
    public function isConfigured(int $tenantId): bool
    {
        $appId = TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', '');

        return ! empty($appId) && ! empty($secret);
    }
}
