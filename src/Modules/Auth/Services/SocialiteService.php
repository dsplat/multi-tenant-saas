<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

/**
 * 第三方登录服务（租户级配置）
 *
 * 每个租户独立配置 OAuth 应用
 * 配置存储在 tenant_settings 表，group = 'oauth'
 */
class SocialiteService
{
    public function __construct(private readonly TenantContextContract $tenantContext) {}

    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 生成命名空间化的 provider 标识
     *
     * 格式: {provider}:tenant:{tenantId}
     * 确保同一 OAuth 应用在不同租户间隔离
     */
    public function namespacedProvider(string $provider, int $tenantId): string
    {
        return "{$provider}:tenant:{$tenantId}";
    }

    /**
     * 解析租户 OAuth 回调完整 URL
     *
     * 优先使用 TenantSetting 中存储的值：
     * - 已是完整 URL（http 开头）→ 直接使用
     * - 相对路径或未设置 → 基于租户 domain 动态拼接
     */
    public function resolveRedirectUrl(int $tenantId, string $provider, string $storedRedirect = ''): string
    {
        // 已存储完整 URL
        if ($storedRedirect && str_starts_with($storedRedirect, 'http')) {
            return $storedRedirect;
        }

        // 基于租户域名动态拼接
        $domain = Tenant::where('tenant_id', $tenantId)->value('domain');

        if (! $domain) {
            // 无自定义域名，回退到相对路径（平台域名场景）
            return $storedRedirect ?: "/auth/{$provider}/callback";
        }

        $path = $storedRedirect ?: "/auth/{$provider}/callback";

        return "https://{$domain}{$path}";
    }

    /**
     * 获取租户 OAuth 配置
     */
    protected function getOAuthConfig(int $tenantId, string $provider): array
    {
        $storedRedirect = TenantSetting::get($tenantId, 'oauth', "{$provider}_redirect", '');

        return [
            'client_id' => TenantSetting::get($tenantId, 'oauth', "{$provider}_client_id", ''),
            'client_secret' => TenantSetting::get($tenantId, 'oauth', "{$provider}_client_secret", ''),
            'redirect' => $this->resolveRedirectUrl($tenantId, $provider, $storedRedirect),
        ];
    }

    /**
     * 动态配置 Socialite 驱动（租户级）
     *
     * 使用 app 容器保存原始配置，请求结束后恢复
     * app 容器在 Octane 下按请求隔离，避免跨请求污染
     */
    protected function configureDriver(string $provider, int $tenantId): void
    {
        $config = $this->getOAuthConfig($tenantId, $provider);

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
    public function resetDriverConfig(string $provider): void
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
    public function getRedirectUrl(string $provider, int $tenantId): string
    {
        if ($provider === 'alipay') {
            return app(AlipayOAuthService::class)->getAuthorizeUrl($tenantId);
        }

        if ($provider === 'wechat_work') {
            return app(WechatWorkOAuthService::class)->getAuthorizeUrl($tenantId);
        }

        if ($provider === 'wechat') {
            return app(WechatOAuthService::class)->getAuthorizeUrl($tenantId);
        }

        $this->configureDriver($provider, $tenantId);

        try {
            return Socialite::driver($provider)
                ->redirect()
                ->getTargetUrl();
        } finally {
            $this->resetDriverConfig($provider);
        }
    }

    /**
     * 处理 OAuth 回调
     *
     * 支付宝走独立的 AlipayOAuthService 流程；其余提供商复用 Socialite，
     * 并捕获 InvalidStateException 显式 abort(403)，避免 state 不匹配被静默忽略
     */
    public function handleCallback(string $provider, int $tenantId): array
    {
        if ($provider === 'alipay') {
            return app(AlipayOAuthService::class)->handleCallback($tenantId);
        }

        if ($provider === 'wechat_work') {
            return app(WechatWorkOAuthService::class)->handleCallback($tenantId);
        }

        if ($provider === 'wechat') {
            return app(WechatOAuthService::class)->handleCallback($tenantId);
        }

        $this->configureDriver($provider, $tenantId);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException $e) {
            abort(403, trans('common.oauth_state_invalid'));
        } finally {
            $this->resetDriverConfig($provider);
        }

        // 查找或创建用户
        $user = $this->findOrCreateUser($socialUser, $provider, $tenantId);

        // 记录 OAuth 账号
        $this->recordOAuthAccount($user, $socialUser, $provider, $tenantId);

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
    protected function findOrCreateUser($socialUser, string $provider, int $tenantId): User
    {
        $nsProvider = $this->namespacedProvider($provider, $tenantId);

        // 先通过 OAuth 账号查找（命名空间化 provider）
        $oauthAccount = OauthAccount::where('provider', $nsProvider)
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
            ]);

            // 关联到租户
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
    protected function recordOAuthAccount(User $user, $socialUser, string $provider, int $tenantId): void
    {
        $nsProvider = $this->namespacedProvider($provider, $tenantId);

        OauthAccount::updateOrCreate(
            [
                'user_id' => $user->user_id,
                'provider' => $nsProvider,
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
    public function isConfigured(int $tenantId, string $provider): bool
    {
        if ($provider === 'alipay') {
            return app(AlipayOAuthService::class)->isConfigured($tenantId);
        }

        if ($provider === 'wechat_work') {
            return app(WechatWorkOAuthService::class)->isConfigured($tenantId);
        }

        if ($provider === 'wechat') {
            return app(WechatOAuthService::class)->isConfigured($tenantId);
        }

        $config = $this->getOAuthConfig($tenantId, $provider);

        return ! empty($config['client_id']) && ! empty($config['client_secret']);
    }

    /**
     * 获取租户 OAuth 配置（用于后台展示）
     */
    public function getOAuthConfigForDisplay(int $tenantId): array
    {
        $providers = ['wechat', 'wechat_work', 'dingtalk', 'feishu', 'github', 'google', 'alipay'];
        $result = [];

        foreach ($providers as $provider) {
            if ($provider === 'alipay') {
                $appId = TenantSetting::get($tenantId, 'oauth', 'alipay_app_id', '');
                $result[$provider] = [
                    'configured' => app(AlipayOAuthService::class)->isConfigured($tenantId),
                    'app_id' => $appId,
                    'mode' => TenantSetting::get($tenantId, 'oauth', 'alipay_mode', 'production'),
                    'redirect' => $this->resolveRedirectUrl($tenantId, 'alipay', TenantSetting::get($tenantId, 'oauth', 'alipay_redirect', '')),
                ];

                continue;
            }

            if ($provider === 'wechat_work') {
                $corpId = TenantSetting::get($tenantId, 'oauth', 'wechat_work_corp_id', '');
                $result[$provider] = [
                    'configured' => app(WechatWorkOAuthService::class)->isConfigured($tenantId),
                    'corp_id' => $corpId,
                    'agent_id' => TenantSetting::get($tenantId, 'oauth', 'wechat_work_agent_id', ''),
                    'redirect' => $this->resolveRedirectUrl($tenantId, 'wechat_work', TenantSetting::get($tenantId, 'oauth', 'wechat_work_redirect', '')),
                ];

                continue;
            }

            if ($provider === 'wechat') {
                $result[$provider] = [
                    'configured' => app(WechatOAuthService::class)->isConfigured($tenantId),
                    'client_id' => TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', ''),
                    'redirect' => $this->resolveRedirectUrl($tenantId, 'wechat', TenantSetting::get($tenantId, 'oauth', 'wechat_redirect', '')),
                ];

                continue;
            }

            $config = $this->getOAuthConfig($tenantId, $provider);
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
    public function updateOAuthConfig(int $tenantId, string $provider, array $config): void
    {
        // wechat_work 使用 corp_id/agent_id/secret 模式，非标准 client_id/client_secret
        $sensitiveKeys = $provider === 'wechat_work'
            ? ['secret']
            : ['client_secret'];

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
    public function getSupportedProviders(): array
    {
        return [
            'wechat' => ['name' => trans('common.wechat'), 'icon' => 'wechat'],
            'wechat_work' => ['name' => trans('common.wechat_work'), 'icon' => 'wechat_work'],
            'dingtalk' => ['name' => trans('common.dingtalk'), 'icon' => 'dingtalk'],
            'feishu' => ['name' => trans('common.feishu'), 'icon' => 'feishu'],
            'github' => ['name' => 'GitHub', 'icon' => 'github'],
            'google' => ['name' => 'Google', 'icon' => 'google'],
            'alipay' => ['name' => trans('common.alipay'), 'icon' => 'alipay'],
        ];
    }
}
