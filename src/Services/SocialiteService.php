<?php

namespace MultiTenantSaas\Services;

use Laravel\Socialite\Facades\Socialite;
use MultiTenantSaas\Models\OauthAccount;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\TenantSetting;

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
     */
    protected static function configureDriver(string $provider, int $tenantId): void
    {
        $config = self::getOAuthConfig($tenantId, $provider);

        // 过滤空值
        $config = array_filter($config, fn($v) => $v !== '' && $v !== null);

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new \RuntimeException("租户 {$tenantId} 未配置 {$provider} OAuth");
        }

        // 动态设置配置
        // TODO: Octane 安全 - config() 写入在请求间持久化
        // 正确做法：使用 Socialite::driver()->setConfig() 传递租户配置
        // 临时方案：请求结束时还原
        config(["services.{$provider}" => $config]);
    }

    /**
     * 还原 OAuth 配置（请求结束时调用）
     */
    public static function resetDriverConfig(string $provider): void
    {
        config(["services.{$provider}" => null]);
    }

    /**
     * 获取 OAuth 重定向 URL
     */
    public static function getRedirectUrl(string $provider, int $tenantId): string
    {
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
     */
    public static function handleCallback(string $provider, int $tenantId): array
    {
        self::configureDriver($provider, $tenantId);

        try {
            $socialUser = Socialite::driver($provider)->user();
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

        if (!$user) {
            // 创建新用户
            $user = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                'email' => $socialUser->getEmail(),
                'password' => bcrypt(\Illuminate\Support\Str::random(32)),
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
        return !empty($config['client_id']) && !empty($config['client_secret']);
    }

    /**
     * 获取租户 OAuth 配置（用于后台展示）
     */
    public static function getOAuthConfigForDisplay(int $tenantId): array
    {
        $providers = ['wechat', 'dingtalk', 'feishu', 'github', 'google'];
        $result = [];

        foreach ($providers as $provider) {
            $config = self::getOAuthConfig($tenantId, $provider);
            $result[$provider] = [
                'configured' => !empty($config['client_id']) && !empty($config['client_secret']),
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
            'wechat' => ['name' => trans("common.wechat"), 'icon' => 'wechat'],
            'dingtalk' => ['name' => trans("common.dingtalk"), 'icon' => 'dingtalk'],
            'feishu' => ['name' => trans("common.feishu"), 'icon' => 'feishu'],
            'github' => ['name' => 'GitHub', 'icon' => 'github'],
            'google' => ['name' => 'Google', 'icon' => 'google'],
        ];
    }
}
