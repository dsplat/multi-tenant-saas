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
 * - idp_login_path: 前往登录路径（standard 默认 /authorize，legacy 默认 /login/{provider}）
 * - idp_redirect_uri: 回跳地址覆盖（默认按租户域名自动推导）
 *
 * 兼容旧 key（过渡期）：
 * - identity_provider_url → idp_base_url
 * - identity_provider_login_path → idp_login_path（仅 legacy）
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
        $callbackUrl = $this->resolveCallbackUrl($tenantId, $provider);

        if ($this->getProtocol($tenantId) === 'standard') {
            // 标准协议：{login_path}?client_id=&redirect_uri=&state=&provider=
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

            $loginPath = $this->getLoginPath($tenantId, '/authorize');

            return "{$base}{$loginPath}?{$params}";
        }

        // 兼容模式：{login_path}?redirect=
        $loginPath = $this->getLoginPath($tenantId, "/login/{$provider}");

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
        $callbackUrl = $this->resolveCallbackUrl($tenantId, $provider);

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
     * 字段映射：IdP 返回 → 框架 users 表
     *
     * 默认兼容两种格式：
     * - 标准 OIDC: sub, name, avatar, mobile/phone_number, email
     * - lanyantu IdP: guid, nickname, avatar, mobile, email
     *
     * 租户可通过 tenant_settings (group=oauth, key=idp_field_mapping) 自定义覆盖。
     * 格式: JSON 对象，key=框架字段，value=IdP 字段（支持 "a|b" 表示优先 a 回退 b）
     *
     * 示例:
     * {
     *   "external_id": "sub|guid",
     *   "name": "name|nickname",
     *   "avatar": "avatar|headimgurl",
     *   "phone": "mobile|phone_number",
     *   "email": "email",
     *   "phone_verified": "mobile_verified|phone_verified",
     *   "email_verified": "email_verified"
     * }
     */
    protected const DEFAULT_FIELD_MAPPING = [
        'external_id' => 'sub|guid',
        'name' => 'name|nickname',
        'avatar' => 'avatar|headimgurl',
        'phone' => 'mobile|phone_number|phone',
        'email' => 'email',
        'phone_verified' => 'mobile_verified|phone_verified',
        'email_verified' => 'email_verified',
    ];

    /**
     * 根据 IdP 返回的用户信息，查找或创建本地用户
     */
    protected function provisionUser(array $idpUser, int $tenantId, string $provider, string $idpToken): array
    {
        $mapped = $this->mapFields($idpUser, $tenantId);

        $guid = $mapped['external_id'];
        $nickname = $mapped['name'];
        $avatar = $mapped['avatar'];
        $mobile = $mapped['phone'];
        $email = $mapped['email'];
        $phoneVerified = $mapped['phone_verified'];
        $emailVerified = $mapped['email_verified'];
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
            $this->syncUserProfile($oauthAccount->user, $mapped);
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
                    $this->syncUserProfile($byUnionid->user, $mapped);
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
                $this->syncUserProfile($user, $mapped);
                $this->recordIdpAccount($user, $guid, $nickname, $avatar, $tenantId, $provider, $idpToken, $oauthBindings);

                return $this->buildResult($user, $provider);
            }
        }

        // 4. 通过邮箱查找
        if ($email !== '' && ! preg_match('/@(wechat|wechat_work|idp)$/', $email)) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $this->ensureTenantUser($user, $tenantId);
                $this->syncUserProfile($user, $mapped);
                $this->recordIdpAccount($user, $guid, $nickname, $avatar, $tenantId, $provider, $idpToken, $oauthBindings);

                return $this->buildResult($user, $provider);
            }
        }

        // 5. 创建用户（delegated 模式下 IdP 已验证联系方式，直接写入）
        $user = User::create([
            'name' => $nickname ?: ('idp_' . Str::limit($guid, 8)),
            'email' => $email ?: ($guid . '@' . $provider),
            'password' => bcrypt(Str::random(32)),
            'avatar' => $avatar ?: null,
            'phone' => $mobile ?: null,
            'phone_verified_at' => ($mobile && $phoneVerified) ? now() : null,
            'email_verified_at' => ($email && $emailVerified) ? now() : null,
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

    /**
     * 字段映射：从 IdP 原始数据提取框架所需字段
     *
     * 支持 "a|b|c" 优先级语法：取第一个非空值
     */
    protected function mapFields(array $idpUser, int $tenantId): array
    {
        $mapping = $this->getFieldMapping($tenantId);
        $result = [];

        foreach ($mapping as $frameworkField => $idpFields) {
            $candidates = explode('|', $idpFields);
            $value = '';

            foreach ($candidates as $field) {
                $field = trim($field);
                if (isset($idpUser[$field]) && $idpUser[$field] !== '' && $idpUser[$field] !== null) {
                    $value = (string) $idpUser[$field];
                    break;
                }
            }

            $result[$frameworkField] = $value;
        }

        // phone_verified / email_verified 转为布尔
        $result['phone_verified'] = in_array(strtolower($result['phone_verified'] ?? ''), ['1', 'true', 'yes'], true);
        $result['email_verified'] = in_array(strtolower($result['email_verified'] ?? ''), ['1', 'true', 'yes'], true);

        return $result;
    }

    /**
     * 同步用户资料（IdP 数据覆盖本地，仅更新空字段或 IdP 有值的字段）
     */
    protected function syncUserProfile(User $user, array $mapped): void
    {
        $updates = [];

        if ($mapped['name'] !== '' && ($user->name === '' || str_starts_with($user->name, 'idp_'))) {
            $updates['name'] = $mapped['name'];
        }

        if ($mapped['avatar'] !== '' && ! $user->avatar) {
            $updates['avatar'] = $mapped['avatar'];
        }

        if ($mapped['phone'] !== '' && ! $user->phone) {
            $updates['phone'] = $mapped['phone'];
            if ($mapped['phone_verified']) {
                $updates['phone_verified_at'] = now();
            }
        }

        // 本地邮箱为空或为占位符（guid@idp 等）时，用 IdP 真实邮箱覆盖
        $isPlaceholderEmail = (bool) preg_match('/@(wechat|wechat_work|idp)$/', $user->email ?? '');
        if ($mapped['email'] !== '' && (! $user->email || $isPlaceholderEmail)) {
            $updates['email'] = $mapped['email'];
            if ($mapped['email_verified']) {
                $updates['email_verified_at'] = now();
            }
        }

        if (! empty($updates)) {
            $user->update($updates);
        }
    }

    protected function getFieldMapping(int $tenantId): array
    {
        $custom = TenantSetting::get($tenantId, 'oauth', 'idp_field_mapping', '');

        if ($custom !== '') {
            $decoded = json_decode($custom, true);
            if (is_array($decoded) && ! empty($decoded)) {
                // 合并：自定义覆盖默认
                return array_merge(self::DEFAULT_FIELD_MAPPING, $decoded);
            }
        }

        return self::DEFAULT_FIELD_MAPPING;
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

    /**
     * 前往登录路径（相对 idp_base_url）
     *
     * 优先 idp_login_path，兼容旧 key identity_provider_login_path，最后用协议默认值
     */
    protected function getLoginPath(int $tenantId, string $default): string
    {
        $path = TenantSetting::get($tenantId, 'oauth', 'idp_login_path', '');
        if ($path === '') {
            $path = TenantSetting::get($tenantId, 'oauth', 'identity_provider_login_path', '');
        }
        if ($path === '') {
            return $default;
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * 回跳地址（IdP 授权完成后回调本系统的 URL）
     *
     * 优先 idp_redirect_uri 配置覆盖，否则按租户域名自动推导
     * 支持 {provider} 占位符
     */
    public function resolveCallbackUrl(int $tenantId, string $provider): string
    {
        $custom = TenantSetting::get($tenantId, 'oauth', 'idp_redirect_uri', '');

        if ($custom !== '') {
            return str_replace('{provider}', $provider, $custom);
        }

        return app(SocialiteService::class)->resolveRedirectUrl($tenantId, $provider);
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
