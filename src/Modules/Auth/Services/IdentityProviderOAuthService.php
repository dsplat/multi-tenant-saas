<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

/**
 * 委托式 OAuth 服务（Identity Provider 模式）
 *
 * 支持两种协议：
 * - standard: 标准 authorization_code 流程（/authorize → /token），需 client_id + client_secret
 * - legacy: 兼容模式（/login/wechat?redirect= → /verify + JWT 直传）
 *
 * 租户配置（tenant_settings group=oauth）：
 * - oauth_mode: "delegated"
 * - idp_base_url: "https://id.lanyantu.com"
 * - idp_protocol: "standard" | "legacy"（默认 legacy）
 * - idp_client_id: "scrm_prod"（standard 必须）
 * - idp_client_secret: "<secret>"（standard 必须）
 *
 * 兼容旧 key（过渡期）：
 * - identity_provider_url → idp_base_url
 * - identity_provider_login_path → 仅 legacy 使用
 * - identity_provider_verify_path → 仅 legacy 使用
 */
class IdentityProviderOAuthService
{
    /**
     * 获取委托登录跳转 URL
     */
    public function getRedirectUrl(int $tenantId, string $provider): string
    {
        $base = $this->getBaseUrl($tenantId);
        $callbackUrl = app(SocialiteService::class)->resolveRedirectUrl($tenantId, $provider);

        if ($this->getProtocol($tenantId) === 'standard') {
            // 标准协议：/authorize?client_id=&redirect_uri=&state=&provider=
            $state = Str::random(32);
            // 存 state 到 cache（5 分钟有效）
            \Illuminate\Support\Facades\Cache::put("idp_state:{$state}", $tenantId, 300);

            $params = http_build_query([
                'client_id' => $this->getClientId($tenantId),
                'redirect_uri' => $callbackUrl,
                'scope' => 'openid profile',
                'state' => $state,
                'provider' => $provider,
            ]);

            return "{$base}/authorize?{$params}";
        }

        // 兼容模式：/login/wechat?redirect=
        $loginPath = TenantSetting::get($tenantId, 'oauth', 'identity_provider_login_path', '/login/wechat');

        return "{$base}{$loginPath}?redirect=" . urlencode($callbackUrl);
    }

    /**
     * 处理委托回调
     */
    public function handleCallback(int $tenantId, string $provider): array
    {
        if ($this->getProtocol($tenantId) === 'standard') {
            return $this->handleStandardCallback($tenantId, $provider);
        }

        return $this->handleLegacyCallback($tenantId, $provider);
    }

    /**
     * 检查租户是否配置了委托模式
     */
    public function isConfigured(int $tenantId): bool
    {
        $mode = TenantSetting::get($tenantId, 'oauth', 'oauth_mode', 'direct');

        return $mode === 'delegated' && $this->getBaseUrl($tenantId) !== '';
    }

    // ==================== 标准协议（authorization_code） ====================

    protected function handleStandardCallback(int $tenantId, string $provider): array
    {
        $code = (string) request()->input('code', '');
        $state = (string) request()->input('state', '');

        if ($code === '' || $state === '') {
            throw new \RuntimeException(trans('common.invalid_request'));
        }

        // 验证 state（防 CSRF）
        $cachedTenant = \Illuminate\Support\Facades\Cache::pull("idp_state:{$state}");
        if ($cachedTenant === null) {
            throw new \RuntimeException(trans('common.oauth_state_invalid'));
        }

        // 用 code 换 token（POST /token）
        $base = $this->getBaseUrl($tenantId);
        $callbackUrl = app(SocialiteService::class)->resolveRedirectUrl($tenantId, $provider);

        $response = Http::timeout(10)->asForm()->post("{$base}/token", [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->getClientId($tenantId),
            'client_secret' => $this->getClientSecret($tenantId),
            'redirect_uri' => $callbackUrl,
        ]);

        if (! $response->successful()) {
            $err = $response->json('error_description', $response->json('message', 'Token exchange failed'));
            throw new \RuntimeException($err);
        }

        $data = $response->json();
        $idpUser = $data['user'] ?? [];

        return $this->provisionUser($idpUser, $tenantId, $provider, $data['access_token'] ?? '');
    }

    // ==================== 兼容模式（JWT 直传） ====================

    protected function handleLegacyCallback(int $tenantId, string $provider): array
    {
        $token = (string) request()->input('token', '');

        if ($token === '') {
            throw new \RuntimeException(trans('common.invalid_request'));
        }

        $base = $this->getBaseUrl($tenantId);
        $verifyPath = TenantSetting::get($tenantId, 'oauth', 'identity_provider_verify_path', '/verify');

        $headers = [];
        // 如果配置了 client 凭证，附加鉴权头
        $clientId = $this->getClientId($tenantId);
        if ($clientId !== '') {
            $headers['X-Client-Id'] = $clientId;
            $headers['X-Client-Secret'] = $this->getClientSecret($tenantId);
        }

        $response = Http::timeout(10)->withHeaders($headers)->post("{$base}{$verifyPath}", [
            'token' => $token,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Identity provider verification failed');
        }

        $data = $response->json();

        if (empty($data['success']) || empty($data['data']['valid'])) {
            throw new \RuntimeException('Identity provider token invalid');
        }

        $idpUser = $data['data']['user'] ?? [];

        return $this->provisionUser($idpUser, $tenantId, $provider, $token);
    }

    // ==================== 公共：用户创建/查找 ====================

    /**
     * 根据 IdP 返回的用户信息，查找或创建本地用户
     *
     * 支持标准协议字段（sub/name/mobile/email/oauth_bindings）
     * 和兼容字段（guid/nickname/avatar）
     */
    protected function provisionUser(array $idpUser, int $tenantId, string $provider, string $idpToken): array
    {
        $guid = (string) ($idpUser['sub'] ?? $idpUser['guid'] ?? '');
        $nickname = $idpUser['name'] ?? $idpUser['nickname'] ?? '';
        $avatar = $idpUser['avatar'] ?? '';
        $mobile = $idpUser['mobile'] ?? $idpUser['phone'] ?? '';
        $email = $idpUser['email'] ?? '';
        $oauthBindings = $idpUser['oauth_bindings'] ?? [];

        if ($guid === '') {
            throw new \RuntimeException('IdP returned empty user identifier');
        }

        $nsProvider = app(SocialiteService::class)->namespacedProvider($provider, $tenantId);

        // 1. 通过 provider_id (guid) 查找已有绑定
        $oauthAccount = OauthAccount::where('provider', $nsProvider)
            ->where('provider_id', $guid)
            ->first();

        if ($oauthAccount && $oauthAccount->user) {
            $this->ensureTenantUser($oauthAccount->user, $tenantId);
            $this->updateOAuthBindings($oauthAccount->user, $oauthBindings, $tenantId, $provider);

            return $this->buildResult($oauthAccount->user, $provider);
        }

        // 2. 通过 unionid 查找（跨 provider 匹配）
        foreach ($oauthBindings as $binding) {
            $unionid = $binding['unionid'] ?? '';
            if ($unionid !== '') {
                $byUnionid = OauthAccount::where('unionid', $unionid)->first();
                if ($byUnionid && $byUnionid->user) {
                    $this->ensureTenantUser($byUnionid->user, $tenantId);
                    $this->updateOAuthBindings($byUnionid->user, $oauthBindings, $tenantId, $provider);

                    return $this->buildResult($byUnionid->user, $provider);
                }
            }
        }

        // 3. 通过手机号查找
        if ($mobile !== '') {
            $user = User::where('phone', $mobile)->first();
            if ($user) {
                $this->ensureTenantUser($user, $tenantId);
                $this->recordIdpAccount($user, $guid, $nickname, $avatar, $tenantId, $provider, $idpToken, $oauthBindings);

                return $this->buildResult($user, $provider);
            }
        }

        // 4. 通过邮箱查找
        if ($email !== '' && ! preg_match('/@(wechat|wechat_work|idp)$/', $email)) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $this->ensureTenantUser($user, $tenantId);
                $this->recordIdpAccount($user, $guid, $nickname, $avatar, $tenantId, $provider, $idpToken, $oauthBindings);

                return $this->buildResult($user, $provider);
            }
        }

        // 5. 创建最小注册用户
        $user = User::create([
            'name' => $nickname ?: ('idp_' . Str::limit($guid, 8)),
            'email' => $guid . '@' . $provider,
            'password' => bcrypt(Str::random(32)),
            'avatar' => $avatar ?: null,
            'phone' => $mobile ?: null,
            'phone_verified_at' => $mobile ? now() : null,
        ]);

        TenantUser::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->user_id,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $this->recordIdpAccount($user, $guid, $nickname, $avatar, $tenantId, $provider, $idpToken, $oauthBindings);

        return $this->buildResult($user, $provider);
    }

    protected function buildResult(User $user, string $provider): array
    {
        return [
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $user->createToken("{$provider}-login")->plainTextToken,
        ];
    }

    /**
     * 记录 IdP OAuth 账号 + 冗余 oauth_bindings
     */
    protected function recordIdpAccount(
        User $user,
        string $guid,
        string $nickname,
        string $avatar,
        int $tenantId,
        string $provider,
        string $idpToken,
        array $oauthBindings = []
    ): void {
        $nsProvider = app(SocialiteService::class)->namespacedProvider($provider, $tenantId);

        // 主记录：IdP guid
        OauthAccount::updateOrCreate(
            [
                'user_id' => $user->user_id,
                'provider' => $nsProvider,
                'provider_id' => $guid,
            ],
            [
                'tenant_id' => $tenantId,
                'provider_name' => $nickname ?: null,
                'provider_avatar' => $avatar ?: null,
                'access_token' => encrypt($idpToken),
                'metadata' => ['mode' => 'delegated'],
            ]
        );

        // 冗余写入 oauth_bindings（unionid/openid/appid）
        $this->updateOAuthBindings($user, $oauthBindings, $tenantId, $provider);
    }

    /**
     * 将 IdP 返回的 oauth_bindings 写入本地 oauth_accounts
     */
    protected function updateOAuthBindings(User $user, array $bindings, int $tenantId, string $provider): void
    {
        foreach ($bindings as $binding) {
            $bProvider = $binding['provider'] ?? $provider;
            $openid = $binding['openid'] ?? '';
            $unionid = $binding['unionid'] ?? '';
            $appid = $binding['appid'] ?? '';

            if ($openid === '') {
                continue;
            }

            $nsProvider = app(SocialiteService::class)->namespacedProvider($bProvider, $tenantId);

            OauthAccount::updateOrCreate(
                [
                    'provider' => $nsProvider,
                    'provider_id' => $openid,
                ],
                [
                    'user_id' => $user->user_id,
                    'tenant_id' => $tenantId,
                    'unionid' => $unionid ?: null,
                    'openid' => $openid,
                    'appid' => $appid ?: null,
                    'metadata' => ['source' => 'idp_binding'],
                ]
            );
        }
    }

    // ==================== 配置读取 ====================

    protected function getBaseUrl(int $tenantId): string
    {
        // 优先新 key，兼容旧 key
        $url = TenantSetting::get($tenantId, 'oauth', 'idp_base_url', '');
        if ($url === '') {
            $url = TenantSetting::get($tenantId, 'oauth', 'identity_provider_url', '');
        }

        return rtrim($url, '/');
    }

    protected function getProtocol(int $tenantId): string
    {
        return TenantSetting::get($tenantId, 'oauth', 'idp_protocol', 'legacy');
    }

    protected function getClientId(int $tenantId): string
    {
        return TenantSetting::get($tenantId, 'oauth', 'idp_client_id', '');
    }

    protected function getClientSecret(int $tenantId): string
    {
        return TenantSetting::get($tenantId, 'oauth', 'idp_client_secret', '');
    }

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
}
