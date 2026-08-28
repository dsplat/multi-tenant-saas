<?php

namespace MultiTenantSaas\Modules\WechatWork\Services;

use Illuminate\Support\Facades\Cache;
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
use MultiTenantSaas\Support\WechatWork\WechatWorkProxy;

/**
 * 企业微信 OAuth 认证服务（WechatWork 模块承载,9.6 从 Auth 模块迁入）
 *
 * 企业微信 OAuth 与标准 OAuth2 有本质差异：
 * - 使用 corp_id + agent_id + secret（非 client_id/client_secret）
 * - 授权端点独立：https://open.work.weixin.qq.com/wwopen/sso/qrConnect
 * - access_token 通过 corp_id + secret 获取（非 code 换取），有效期 7200s
 * - 用户身份通过 code + access_token 获取（userid），再读取用户详情
 *
 * 因此不能复用 Socialite 的 OAuth2 Provider，需独立实现。
 *
 * 凭证来源双轨（2026-08 新增）：
 * - 自建应用模式（mode=self）：租户手填凭证，存储于 tenant_settings group='wechatwork'
 * - 代开发模式（mode=suite）：租户扫码授权服务商代开发应用，permanent_code 充当
 *   secret 角色（wechat_work_authorizations 表），回调域用平台统一回调域
 *   （服务商代配可信域名=平台域，绕过自建应用的主体校验限制）
 *
 * 模块边界（9.6 决策）：WechatWork 模块承载企微一切（凭证/token/回调/权限/IP 代理），
 * Auth 侧 SocialiteService/TenantOAuthController 仅委托调用；oauth 组只保留
 * wechat_work_enabled 开关，WW_verify 域名验证走 domain.verification_token
 * （VerificationFileController 消费）。
 *
 * 租户级配置（tenant_settings, group='wechatwork'）：
 *  - corp_id    企业 ID（不加密）
 *  - agent_id   应用 AgentId（不加密）
 *  - secret     应用 Secret（加密）
 *  - redirect   回调 URL（不加密）
 * 旧 oauth.wechat_work_* 存量配置读取时自动迁移到新组（读新写旧）。
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
     * 租户级配置组（WechatWork 模块独立承载全部企微配置）
     */
    protected const CONFIG_GROUP = 'wechatwork';

    /**
     * 读取企微租户配置（读时迁移）
     *
     * 新组 wechatwork.{key} 优先；旧 oauth.wechat_work_{key} 存量配置
     * 读取后回写新组（读新写旧，幂等），保证零迁移平滑过渡。
     * 返回值保持 TenantSetting 原样（JSON 解码行为，数字字符串 → int），与迁移前一致。
     */
    protected function setting(int $tenantId, string $key, mixed $default = ''): mixed
    {
        $new = TenantSetting::get($tenantId, self::CONFIG_GROUP, $key, null);
        if ($new !== null && $new !== '') {
            return $new;
        }

        $legacy = TenantSetting::get($tenantId, 'oauth', "wechat_work_{$key}", '');
        if ($legacy !== '' && $legacy !== null) {
            TenantSetting::set($tenantId, self::CONFIG_GROUP, $key, $legacy, $key === 'secret');

            return $legacy;
        }

        return $default;
    }

    /**
     * 获取租户企业微信配置
     *
     * 凭证来源双轨：租户已完成代开发授权（wechat_work_authorizations
     * status=authorized）时优先走代开发模式（mode=suite，permanent_code
     * 充当 secret，回调域用平台统一回调域）；否则走自建应用模式
     * （mode=self，tenant_settings 手填凭证）。
     *
     * @throws \RuntimeException 当两种模式均未配置
     */
    protected function getConfig(int $tenantId): array
    {
        $authorization = $this->suiteAuthorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            $callbackDomain = config('auth.oauth.callback_domain', '');
            $redirect = $callbackDomain !== ''
                ? "https://{$callbackDomain}/api/v1/auth/wechat_work/callback"
                : $this->resolveWechatWorkRedirectUrl($tenantId, '');

            return [
                'corp_id' => $authorization->corp_id,
                'agent_id' => (string) ($authorization->agent_id ?? ''),
                'secret' => (string) $authorization->permanent_code,
                'redirect' => $redirect,
                'mode' => 'suite',
            ];
        }

        $corpId = $this->setting($tenantId, 'corp_id');
        $secret = $this->setting($tenantId, 'secret');

        if (empty($corpId) || empty($secret)) {
            throw new ServiceUnavailableException(trans('common.oauth_not_configured', ['provider' => 'wechat_work', 'tenant' => $tenantId]));
        }

        return [
            'corp_id' => $corpId,
            'agent_id' => $this->setting($tenantId, 'agent_id'),
            'secret' => $secret,
            'redirect' => $this->resolveWechatWorkRedirectUrl($tenantId, $this->setting($tenantId, 'redirect')),
            'mode' => 'self',
        ];
    }

    /**
     * 解析企微回调完整 URL（与 SocialiteService::resolveRedirectUrl 同步实现）
     *
     * 9.6 迁移后 WechatWork 模块自包含，消除对 Auth 模块的辅助方法依赖；
     * 逻辑保持与 SocialiteService::resolveRedirectUrl 一致，后续演化需同步。
     *
     * 优先级：
     * 1. 租户显式存储的完整 URL（自选回调地址，最高）
     * 2. 租户自定义域名（tenants.domain）：回调域要求备案主体与企业主体一致，
     *    平台统一回调域过不了主体校验（2026-08 生产实锤）
     * 3. 平台统一回调域（OAUTH_CALLBACK_DOMAIN）：仅无自定义域名的租户使用
     * 4. 相对路径兜底（平台域场景）
     */
    protected function resolveWechatWorkRedirectUrl(int $tenantId, string $storedRedirect): string
    {
        // 已存储完整 URL（显式覆盖）
        if ($storedRedirect && str_starts_with($storedRedirect, 'http')) {
            return $storedRedirect;
        }

        // 租户自定义域名优先（主体校验要求域名归租户企业所有）
        $domain = Tenant::where('tenant_id', $tenantId)->value('domain');
        if ($domain) {
            return "https://{$domain}/api/v1/auth/wechat_work/callback";
        }

        // 无自定义域名 → 平台统一回调域（平台级虚拟 IDP）
        $callbackDomain = config('auth.oauth.callback_domain', '');
        if ($callbackDomain !== '') {
            return "https://{$callbackDomain}/api/v1/auth/wechat_work/callback";
        }

        return $storedRedirect ?: "/api/v1/auth/wechat_work/callback";
    }

    /**
     * 读取代开发授权记录（双轨查询入口）
     *
     * WechatWork 模块为可选拆包（dsplat/multi-tenant-saas-module-wechatwork），
     * 下游未安装或未迁移时类/表缺失：返回 null 回退自建应用模式，不得抛 SQL 错误。
     */
    protected function suiteAuthorization(int $tenantId)
    {
        if (! class_exists(WechatWorkSuiteService::class)) {
            return null;
        }

        if (! Schema::hasTable('wechat_work_authorizations')) {
            return null;
        }

        return app(WechatWorkSuiteService::class)->authorization($tenantId);
    }

    /**
     * 生成授权跳转 URL（扫码登录页）
     *
     * @param  string  $originDomain  用户来源域名（回调后回跳）
     */
    public function getAuthorizeUrl(int $tenantId, string $originDomain = ''): string
    {
        $config = $this->getConfig($tenantId);

        $state = $this->generateState($tenantId, 'wechat_work', ['origin_domain' => $originDomain]);

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

        $context = $this->verifyState($state, $tenantId, 'wechat_work');

        if ($code === '') {
            throw new DomainException(trans('common.invalid_request'));
        }

        $accessToken = $this->getAccessToken($tenantId);
        $userIdentity = $this->getUserIdentity($tenantId, $accessToken, $code);

        // 企业微信 userid 是企业在内部标识用户的唯一 ID（新版 API 返回小写 userid，旧版大写 UserId）
        $userId = $userIdentity['userid'] ?? $userIdentity['UserId'] ?? '';
        if ($userId === '') {
            // 非企业成员扫码，返回数据中无 userid，仅有 openid
            $openId = $userIdentity['openid'] ?? $userIdentity['OpenId'] ?? '';
            if ($openId === '') {
                throw new ServiceUnavailableException('WechatWork: neither UserId nor OpenId returned');
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
            'origin_domain' => $context['origin_domain'] ?? '',
        ];
    }

    /**
     * 获取企业微信 access_token（带缓存，有效期 7200s）
     *
     * 代开发模式（mode=suite）走 gettoken（corpid + corpsecret=permanent_code，
     * permanent_code 充当应用 secret）；自建应用模式走 gettoken。
     *
     * @throws \RuntimeException
     */
    public function getAccessToken(int $tenantId): string
    {
        $config = $this->getConfig($tenantId);

        // 代开发模式：corp access_token 由套件服务统一缓存管理
        if (($config['mode'] ?? '') === 'suite') {
            return app(WechatWorkSuiteService::class)->corpAccessToken($tenantId);
        }

        $cacheKey = "wechat_work_token:{$tenantId}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $resp = Http::withOptions(WechatWorkProxy::resolve($tenantId))->get(self::API_BASE . '/gettoken', [
            'corpid' => $config['corp_id'],
            'corpsecret' => $config['secret'],
        ]);

        $data = $this->parseResponse($resp, 'gettoken');

        $token = $data['access_token'] ?? '';
        $expiresIn = (int) ($data['expires_in'] ?? 7200);

        if ($token === '') {
            throw new ServiceUnavailableException('WechatWork: empty access_token returned');
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
        $resp = Http::withOptions(WechatWorkProxy::resolve($tenantId))->get(self::API_BASE . '/auth/getuserinfo', [
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
        $resp = Http::withOptions(WechatWorkProxy::resolve($tenantId))->get(self::API_BASE . '/user/get', [
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
            throw new ServiceUnavailableException("WechatWork API request failed: HTTP {$resp->status()}");
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
            throw new ServiceUnavailableException("WechatWork API error [{$errCode}]: {$errMsg}");
        }

        return $data;
    }

    /**
     * 生成命名空间化的 provider 标识（原 SocialiteService 辅助方法内联，
     * 9.6 迁移后 WechatWork 模块自包含，消除模块反向依赖）
     *
     * 格式: wechat_work:tenant:{tenantId}
     * 确保同一 OAuth 应用在不同租户间隔离
     */
    protected function namespacedProvider(int $tenantId): string
    {
        return "wechat_work:tenant:{$tenantId}";
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
        $nsProvider = $this->namespacedProvider($tenantId);

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
        $nsProvider = $this->namespacedProvider($tenantId);

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
     * 检查租户是否已配置企业微信 OAuth（自建应用或代开发授权任一满足）
     */
    public function isConfigured(int $tenantId): bool
    {
        $authorization = $this->suiteAuthorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            return true;
        }

        $corpId = $this->setting($tenantId, 'corp_id');
        $secret = $this->setting($tenantId, 'secret');

        return ! empty($corpId) && ! empty($secret);
    }
}
