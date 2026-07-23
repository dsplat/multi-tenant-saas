<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

/**
 * 企业微信 OAuth 认证服务
 *
 * 企业微信 OAuth 与标准 OAuth2 有本质差异：
 * - 使用 corp_id + agent_id + secret（非 client_id/client_secret）
 * - 授权端点独立：https://open.work.weixin.qq.com/wwopen/sso/qrConnect
 * - access_token 通过 corp_id + secret 获取（非 code 换取），有效期 7200s
 * - 用户身份通过 code + access_token 获取（userid），再读取用户详情
 *
 * 因此不能复用 Socialite 的 OAuth2 Provider，需独立实现。
 * 仅支持「企业内部应用」模式，第三方应用模式留作后续扩展。
 *
 * 租户级配置（存储在 tenant_settings, group='oauth'）：
 *  - wechat_work_corp_id    企业 ID（不加密）
 *  - wechat_work_agent_id   应用 AgentId（不加密）
 *  - wechat_work_secret     应用 Secret（加密）
 *  - wechat_work_redirect   回调 URL（不加密）
 */
class WechatWorkOAuthService
{
    use ManagesOAuthState;

    /**
     * 企业微信 API 基础地址
     */
    protected const API_BASE = 'https://qyapi.weixin.qq.com/cgi-bin';

    /**
     * 扫码登录授权页地址
     */
    protected const AUTHORIZE_URL = 'https://open.work.weixin.qq.com/wwopen/sso/qrConnect';

    /**
     * 获取租户企业微信配置
     *
     * @throws \RuntimeException 当 corp_id 或 secret 未配置
     */
    protected function getConfig(int $tenantId): array
    {
        $corpId = TenantSetting::get($tenantId, 'oauth', 'wechat_work_corp_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_work_secret', '');

        if (empty($corpId) || empty($secret)) {
            throw new \RuntimeException(trans('common.oauth_not_configured', ['provider' => 'wechat_work', 'tenant' => $tenantId]));
        }

        return [
            'corp_id' => $corpId,
            'agent_id' => TenantSetting::get($tenantId, 'oauth', 'wechat_work_agent_id', ''),
            'secret' => $secret,
            'redirect' => app(SocialiteService::class)->resolveRedirectUrl(
                $tenantId,
                'wechat_work',
                TenantSetting::get($tenantId, 'oauth', 'wechat_work_redirect', '')
            ),
        ];
    }

    /**
     * 生成授权跳转 URL（扫码登录页）
     */
    public function getAuthorizeUrl(int $tenantId): string
    {
        $config = $this->getConfig($tenantId);

        $state = $this->generateState($tenantId, 'wechat_work');

        $params = [
            'appid' => $config['corp_id'],
            'agentid' => $config['agent_id'],
            'redirect_uri' => $config['redirect'],
            'state' => $state,
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * 处理 OAuth 回调，返回用户信息 + token
     *
     * 返回格式与 SocialiteService::handleCallback 一致：
     *  ['user' => [...], 'token' => ...]
     *
     * @throws \RuntimeException 配置缺失或 API 调用失败
     */
    public function handleCallback(int $tenantId): array
    {
        $code = (string) request()->input('code', '');
        $state = (string) request()->input('state', '');

        $this->verifyState($state, $tenantId, 'wechat_work');

        if ($code === '') {
            throw new \RuntimeException(trans('common.invalid_request'));
        }

        $accessToken = $this->getAccessToken($tenantId);
        $userIdentity = $this->getUserIdentity($tenantId, $accessToken, $code);

        // 企业微信 userid 是企业在内部标识用户的唯一 ID
        $userId = $userIdentity['UserId'] ?? '';
        if ($userId === '') {
            // 非企业成员扫码，返回数据中无 UserId，仅有 OpenId
            $openId = $userIdentity['OpenId'] ?? '';
            if ($openId === '') {
                throw new \RuntimeException('WechatWork: neither UserId nor OpenId returned');
            }
            $userId = $openId;
        }

        $userInfo = $this->getUserDetail($tenantId, $accessToken, $userId);

        $user = $this->findOrCreateUser($userInfo, $userId, $tenantId);
        $this->recordOAuthAccount($user, $userInfo, $userId, $accessToken, $tenantId);

        return [
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $user->createToken('wechat_work-login')->plainTextToken,
        ];
    }

    /**
     * 获取企业微信 access_token（带缓存，有效期 7200s）
     *
     * @throws \RuntimeException
     */
    public function getAccessToken(int $tenantId): string
    {
        $config = $this->getConfig($tenantId);
        $cacheKey = "wechat_work_token:{$tenantId}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $resp = Http::get(self::API_BASE . '/gettoken', [
            'corpid' => $config['corp_id'],
            'corpsecret' => $config['secret'],
        ]);

        $data = $this->parseResponse($resp, 'gettoken');

        $token = $data['access_token'] ?? '';
        $expiresIn = (int) ($data['expires_in'] ?? 7200);

        if ($token === '') {
            throw new \RuntimeException('WechatWork: empty access_token returned');
        }

        // 提前 5 分钟过期，避免边界问题
        Cache::put($cacheKey, $token, $expiresIn - 300);

        return $token;
    }

    /**
     * 通过 code 获取用户身份（UserId 或 OpenId）
     *
     * @return array{UserId?: string, OpenId?: string, DeviceId?: string}
     *
     * @throws \RuntimeException
     */
    public function getUserIdentity(int $tenantId, string $accessToken, string $code): array
    {
        $resp = Http::get(self::API_BASE . '/auth/getuserinfo', [
            'access_token' => $accessToken,
            'code' => $code,
        ]);

        return $this->parseResponse($resp, 'auth/getuserinfo');
    }

    /**
     * 获取企业成员详情
     *
     * @return array{userid?: string, name?: string, email?: string, avatar?: string, mobile?: string, position?: string}
     *
     * @throws \RuntimeException
     */
    public function getUserDetail(int $tenantId, string $accessToken, string $userId): array
    {
        $resp = Http::get(self::API_BASE . '/user/get', [
            'access_token' => $accessToken,
            'userid' => $userId,
        ]);

        return $this->parseResponse($resp, 'user/get');
    }

    /**
     * 解析企业微信 API 响应
     *
     * @throws \RuntimeException 当 errcode != 0
     */
    protected function parseResponse($resp, string $api): array
    {
        if (! $resp->successful()) {
            Log::error('[WechatWorkOAuthService] HTTP failed', [
                'api' => $api,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            throw new \RuntimeException("WechatWork API request failed: HTTP {$resp->status()}");
        }

        $data = $resp->json();
        $errCode = $data['errcode'] ?? -1;

        if ($errCode !== 0) {
            $errMsg = $data['errmsg'] ?? 'unknown error';
            Log::error('[WechatWorkOAuthService] API error', [
                'api' => $api,
                'errcode' => $errCode,
                'errmsg' => $errMsg,
            ]);
            throw new \RuntimeException("WechatWork API error [{$errCode}]: {$errMsg}");
        }

        return $data;
    }

    /**
     * 查找或创建用户
     *
     * 1. 通过 OauthAccount (provider='wechat_work:tenant:{id}', provider_id=userid) 查找
     * 2. 不存在则通过邮箱查找或创建 User
     * 3. 创建 TenantUser 关联
     */
    public function findOrCreateUser(array $wwUser, string $userId, int $tenantId): User
    {
        $nsProvider = app(SocialiteService::class)->namespacedProvider('wechat_work', $tenantId);

        $oauthAccount = OauthAccount::where('provider', $nsProvider)
            ->where('provider_id', $userId)
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
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }

            return $existingUser;
        }

        $email = $wwUser['email'] ?? '';
        if (empty($email)) {
            $email = $userId . '@wechat_work';
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $wwUser['name'] ?? ('ww_' . $userId),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'avatar' => $wwUser['avatar'] ?? null,
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
     * 记录 OAuth 账号（token 加密存储）
     */
    protected function recordOAuthAccount(User $user, array $userInfo, string $userId, string $accessToken, int $tenantId): void
    {
        $nsProvider = app(SocialiteService::class)->namespacedProvider('wechat_work', $tenantId);

        OauthAccount::updateOrCreate(
            [
                'user_id' => $user->user_id,
                'provider' => $nsProvider,
                'provider_id' => $userId,
            ],
            [
                'tenant_id' => $tenantId,
                'provider_email' => $userInfo['email'] ?? null,
                'provider_name' => $userInfo['name'] ?? null,
                'provider_avatar' => $userInfo['avatar'] ?? null,
                'access_token' => encrypt($accessToken),
                'token_expires_at' => now()->addSeconds(7200),
            ]
        );
    }

    /**
     * 检查租户是否已配置企业微信 OAuth
     */
    public function isConfigured(int $tenantId): bool
    {
        $corpId = TenantSetting::get($tenantId, 'oauth', 'wechat_work_corp_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_work_secret', '');

        return ! empty($corpId) && ! empty($secret);
    }
}
