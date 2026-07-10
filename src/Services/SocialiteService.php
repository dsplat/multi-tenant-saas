<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use MultiTenantSaas\Models\OauthAccount;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;

/**
 * 第三方登录服务（租户级配置）
 *
 * 每个租户独立配置 OAuth 应用
 * 配置存储在 tenant_settings 表，group = 'oauth'
 */
class SocialiteService
{
    /**
     * 获取租户 OAuth 配置
     */
    protected static function getOAuthConfig(int $tenantId, string $provider): array
    {
        return [
            'client_id' => TenantSetting::get($tenantId, 'oauth', "{$provider}_client_id", ''),
            'client_secret' => TenantSetting::get($tenantId, 'oauth', "{$provider}_client_secret", ''),
            'redirect' => TenantSetting::get($tenantId, 'oauth', "{$provider}_redirect", "/auth/{$provider}/callback"),
        ];
    }

    /**
     * 动态配置 Socialite 驱动（租户级）
     *
     * 使用 app 容器保存原始配置，请求结束后恢复
     * app 容器在 Octane 下按请求隔离，避免跨请求污染
     */
    protected static function configureDriver(string $provider, int $tenantId): void
    {
        $config = self::getOAuthConfig($tenantId, $provider);

        // 过滤空值
        $config = array_filter($config, fn ($v) => $v !== '' && $v !== null);

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new \RuntimeException(trans('common.oauth_not_configured', ['provider' => $provider, 'tenant' => $tenantId]));
        }

        // 保存原始配置到 app 容器（请求级隔离）
        $key = "oauth.original.{$provider}";
        if (! app()->bound($key)) {
            app()->instance($key, config("services.{$provider}"));
        }

        // 动态设置配置
        config(["services.{$provider}" => $config]);
    }

    /**
     * 还原 OAuth 配置（请求结束时调用）
     * 从 app 容器恢复原始值，而非置为 null
     */
    public static function resetDriverConfig(string $provider): void
    {
        $key = "oauth.original.{$provider}";
        if (app()->bound($key)) {
            config(["services.{$provider}" => app($key)]);
            app()->forgetInstance($key);
        }
    }

    /**
     * 获取 OAuth 重定向 URL
     *
     * 支付宝使用 RSA2 签名的独立授权流程，不走 Socialite 驱动
     */
    public static function getRedirectUrl(string $provider, int $tenantId): string
    {
        if ($provider === 'alipay') {
            return app(AlipayOAuthService::class)->getAuthorizeUrl($tenantId);
        }

        self::configureDriver($provider, $tenantId);

        try {
            return Socialite::driver($provider)
                ->redirect()
                ->getTargetUrl();
        } finally {
            self::resetDriverConfig($provider);
        }
    }

    /**
     * 处理 OAuth 回调
     *
     * 支付宝走独立的 AlipayOAuthService 流程；其余提供商复用 Socialite，
     * 并捕获 InvalidStateException 显式 abort(403)，避免 state 不匹配被静默忽略
     */
    public static function handleCallback(string $provider, int $tenantId): array
    {
        if ($provider === 'alipay') {
            return app(AlipayOAuthService::class)->handleCallback($tenantId);
        }

        self::configureDriver($provider, $tenantId);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException $e) {
            abort(403, trans('common.oauth_state_invalid'));
        } finally {
            self::resetDriverConfig($provider);
        }

        // 查找或创建用户
        $user = self::findOrCreateUser($socialUser, $provider, $tenantId);

        // 记录 OAuth 账号
        self::recordOAuthAccount($user, $socialUser, $provider, $tenantId);

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
     * 查找或创建用户
     */
    protected static function findOrCreateUser($socialUser, string $provider, int $tenantId): User
    {
        // 先通过 OAuth 账号查找
        $oauthAccount = OauthAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($oauthAccount) {
            return $oauthAccount->user;
        }

        // 通过邮箱查找
        $user = User::where('email', $socialUser->getEmail())->first();

        if (! $user) {
            // 创建新用户
            $user = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                'email' => $socialUser->getEmail(),
                'password' => bcrypt(Str::random(32)),
                'role' => 'platform_user',
            ]);

            // 关联到租户
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
     * 记录 OAuth 账号（token 加密存储）
     */
    protected static function recordOAuthAccount(User $user, $socialUser, string $provider, int $tenantId): void
    {
        OauthAccount::updateOrCreate(
            [
                'user_id' => $user->user_id,
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
            ],
            [
                'tenant_id' => $tenantId,
                'provider_email' => $socialUser->getEmail(),
                'provider_name' => $socialUser->getName(),
                'provider_avatar' => $socialUser->getAvatar(),
                'access_token' => $socialUser->token ? encrypt($socialUser->token) : null,
                'refresh_token' => $socialUser->refreshToken ? encrypt($socialUser->refreshToken) : null,
                'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
            ]
        );
    }

    /**
     * 检查租户是否已配置 OAuth
     */
    public static function isConfigured(int $tenantId, string $provider): bool
    {
        $config = self::getOAuthConfig($tenantId, $provider);

        return ! empty($config['client_id']) && ! empty($config['client_secret']);
    }

    /**
     * 获取租户 OAuth 配置（用于后台展示）
     */
    public static function getOAuthConfigForDisplay(int $tenantId): array
    {
        $providers = ['wechat', 'dingtalk', 'feishu', 'github', 'google', 'alipay'];
        $result = [];

        foreach ($providers as $provider) {
            $config = self::getOAuthConfig($tenantId, $provider);
            $result[$provider] = [
                'configured' => ! empty($config['client_id']) && ! empty($config['client_secret']),
                'client_id' => $config['client_id'],
                'redirect' => $config['redirect'],
            ];
        }

        return $result;
    }

    /**
     * 更新租户 OAuth 配置
     */
    public static function updateOAuthConfig(int $tenantId, string $provider, array $config): void
    {
        $sensitiveKeys = ['client_secret'];

        foreach ($config as $key => $value) {
            if (in_array($key, $sensitiveKeys) && $value === '********') {
                continue; // 跳过遮罩占位符
            }
            $isEncrypted = in_array($key, $sensitiveKeys);
            TenantSetting::set($tenantId, 'oauth', "{$provider}_{$key}", $value, $isEncrypted);
        }
    }

    /**
     * 获取支持的提供商列表
     */
    public static function getSupportedProviders(): array
    {
        return [
            'wechat' => ['name' => trans('common.wechat'), 'icon' => 'wechat'],
            'dingtalk' => ['name' => trans('common.dingtalk'), 'icon' => 'dingtalk'],
            'feishu' => ['name' => trans('common.feishu'), 'icon' => 'feishu'],
            'github' => ['name' => 'GitHub', 'icon' => 'github'],
            'google' => ['name' => 'Google', 'icon' => 'google'],
            'alipay' => ['name' => trans('common.alipay'), 'icon' => 'alipay'],
        ];
    }
}
