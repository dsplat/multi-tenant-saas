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
 * 适用场景：公司统一认证中心（如 id.lanyantu.com）接管微信/企业微信 OAuth，
 * 各业务系统不直接持有 appid/appsecret，而是跳转到认证中心完成授权后回调。
 *
 * 流程：
 * 1. redirect → 认证中心登录页（携带 redirect 回调地址）
 * 2. 认证中心完成 OAuth → 302 回调到本系统，URL 带 ?token=xxx
 * 3. 本系统用 token 调用认证中心 /verify 接口验证 → 获取用户信息
 * 4. 根据 unionid/guid 查找或创建本地用户
 *
 * 租户配置（tenant_settings group=oauth）：
 * - oauth_mode: "delegated"
 * - identity_provider_url: "https://id.lanyantu.com"
 * - identity_provider_login_path: "/login/wechat"（默认）
 * - identity_provider_verify_path: "/verify"（默认）
 */
class IdentityProviderOAuthService
{
    /**
     * 获取委托登录跳转 URL
     */
    public function getRedirectUrl(int $tenantId, string $provider): string
    {
        $idpUrl = $this->getIdpUrl($tenantId);
        $loginPath = TenantSetting::get($tenantId, 'oauth', 'identity_provider_login_path', '/login/wechat');

        // 回调地址：本系统的 OAuth callback
        $callbackUrl = app(SocialiteService::class)->resolveRedirectUrl($tenantId, $provider);

        return "{$idpUrl}{$loginPath}?redirect=" . urlencode($callbackUrl);
    }

    /**
     * 处理委托回调
     *
     * 认证中心回调时 URL 携带 ?token=xxx（JWT）
     */
    public function handleCallback(int $tenantId, string $provider): array
    {
        $token = (string) request()->input('token', '');

        if ($token === '') {
            throw new \RuntimeException(trans('common.invalid_request'));
        }

        // 调用认证中心 /verify 验证 token
        $idpUrl = $this->getIdpUrl($tenantId);
        $verifyPath = TenantSetting::get($tenantId, 'oauth', 'identity_provider_verify_path', '/verify');

        $response = Http::timeout(10)->post("{$idpUrl}{$verifyPath}", [
            'token' => $token,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Identity provider verification failed');
        }

        $data = $response->json();

        if (empty($data['success']) || empty($data['data']['valid'])) {
            throw new \RuntimeException('Identity provider token invalid');
        }

        $idpUser = $data['data']['user'];
        $guid = $idpUser['guid'] ?? '';
        $nickname = $idpUser['nickname'] ?? '';
        $avatar = $idpUser['avatar'] ?? '';
        $mobile = $idpUser['mobile'] ?? '';
        $email = $idpUser['email'] ?? '';

        // 查找或创建本地用户（以 idp guid 为 provider_id）
        $user = $this->findOrCreateUser($guid, $nickname, $avatar, $mobile, $email, $tenantId, $provider);

        // 记录 OAuth 账号
        $this->recordOAuthAccount($user, $guid, $nickname, $avatar, $tenantId, $provider, $token);

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
     * 检查租户是否配置了委托模式
     */
    public function isConfigured(int $tenantId): bool
    {
        $mode = TenantSetting::get($tenantId, 'oauth', 'oauth_mode', 'direct');

        return $mode === 'delegated' && $this->getIdpUrl($tenantId) !== '';
    }

    /**
     * 查找或创建用户
     *
     * 优先级：
     * 1. 通过 provider_id (guid) 查找已有 OAuth 绑定
     * 2. 通过 mobile 查找已有用户（合并）
     * 3. 通过 email 查找已有用户（合并）
     * 4. 创建新用户（最小注册）
     */
    protected function findOrCreateUser(
        string $guid,
        string $nickname,
        string $avatar,
        string $mobile,
        string $email,
        int $tenantId,
        string $provider
    ): User {
        $nsProvider = app(SocialiteService::class)->namespacedProvider($provider, $tenantId);

        // 1. 已有 OAuth 绑定
        $oauthAccount = OauthAccount::where('provider', $nsProvider)
            ->where('provider_id', $guid)
            ->first();

        if ($oauthAccount && $oauthAccount->user) {
            $this->ensureTenantUser($oauthAccount->user, $tenantId);

            return $oauthAccount->user;
        }

        // 2. 通过手机号查找（认证中心已验证的手机号）
        if ($mobile !== '') {
            $user = User::where('phone', $mobile)->first();
            if ($user) {
                $this->ensureTenantUser($user, $tenantId);

                return $user;
            }
        }

        // 3. 通过邮箱查找
        if ($email !== '' && ! preg_match('/@(wechat|wechat_work)$/', $email)) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $this->ensureTenantUser($user, $tenantId);

                return $user;
            }
        }

        // 4. 创建最小注册用户
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

        return $user;
    }

    /**
     * 记录 OAuth 账号
     */
    protected function recordOAuthAccount(
        User $user,
        string $guid,
        string $nickname,
        string $avatar,
        int $tenantId,
        string $provider,
        string $idpToken
    ): void {
        $nsProvider = app(SocialiteService::class)->namespacedProvider($provider, $tenantId);

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
                'metadata' => ['mode' => 'delegated', 'idp_token' => true],
            ]
        );
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

    protected function getIdpUrl(int $tenantId): string
    {
        return rtrim(TenantSetting::get($tenantId, 'oauth', 'identity_provider_url', ''), '/');
    }
}
