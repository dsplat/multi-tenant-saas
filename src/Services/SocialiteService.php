<?php

namespace MultiTenantSaas\Services;

use Laravel\Socialite\Facades\Socialite;
use MultiTenantSaas\Models\OauthAccount;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\TenantUser;

/**
 * 第三方登录服务
 *
 * 集成 laravel/socialite，支持微信/钉钉/飞书等
 *
 * 配置：config/services.php
 * - wechat: 微信开放平台
 * - dingtalk: 钉钉
 * - feishu: 飞书
 */
class SocialiteService
{
    /**
     * 获取 OAuth 重定向 URL
     */
    public static function getRedirectUrl(string $provider, int $tenantId): string
    {
        return Socialite::driver($provider)
            ->redirect()
            ->getTargetUrl();
    }

    /**
     * 处理 OAuth 回调
     */
    public static function handleCallback(string $provider, int $tenantId): array
    {
        $socialUser = Socialite::driver($provider)->user();

        // 查找或创建用户
        $user = self::findOrCreateUser($socialUser, $provider, $tenantId);

        // 记录 OAuth 账号
        self::recordOAuthAccount($user, $socialUser, $provider, $tenantId);

        return [
            'user' => $user,
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
                'password' => bcrypt(uniqid()),
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
     * 记录 OAuth 账号
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
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
            ]
        );
    }

    /**
     * 获取支持的提供商列表
     */
    public static function getSupportedProviders(): array
    {
        return [
            'wechat' => ['name' => '微信', 'icon' => 'wechat'],
            'dingtalk' => ['name' => '钉钉', 'icon' => 'dingtalk'],
            'feishu' => ['name' => '飞书', 'icon' => 'feishu'],
            'github' => ['name' => 'GitHub', 'icon' => 'github'],
            'google' => ['name' => 'Google', 'icon' => 'google'],
        ];
    }
}
