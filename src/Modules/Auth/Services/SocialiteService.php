<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkOAuthService;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;

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
     * 解析租户 OAuth 回调完整 URL（自定义域名优先）
     *
     * 优先级：
     * 1. 租户显式存储的完整 URL（自选回调地址，最高）
     * 2. 租户自定义域名（tenants.domain）：微信/企微的回调域要求备案主体与
     *    企业主体一致，平台统一回调域过不了主体校验（2026-08 生产实锤），
     *    故默认用租户自有域名做回调域，验证文件（WW_verify 等）走租户域服务。
     * 3. 平台统一回调域（OAUTH_CALLBACK_DOMAIN）：仅无自定义域名的租户使用。
     * 4. 回退按租户域名推导（resolveTenantRedirectUrl）。
     */
    public function resolveRedirectUrl(int $tenantId, string $provider, string $storedRedirect = ''): string
    {
        // 已存储完整 URL（显式覆盖）
        if ($storedRedirect && str_starts_with($storedRedirect, 'http')) {
            return $storedRedirect;
        }

        // 租户自定义域名优先（主体校验要求域名归租户企业所有）
        $domain = Tenant::where('tenant_id', $tenantId)->value('domain');
        if ($domain) {
            return "https://{$domain}/api/v1/auth/{$provider}/callback";
        }

        // 无自定义域名 → 平台统一回调域（平台级虚拟 IDP）
        $callbackDomain = config('auth.oauth.callback_domain', '');
        if ($callbackDomain !== '') {
            return "https://{$callbackDomain}/api/v1/auth/{$provider}/callback";
        }

        return $this->resolveTenantRedirectUrl($tenantId, $provider, $storedRedirect);
    }

    /**
     * 基于租户域名推导回调 URL（原逻辑）
     *
     * 供 IDP 委托模式（delegated）使用：该场景微信回调域归企业 IDP 管理，
     * 本系统回调地址不受微信回调域限制，保持租户域/自定义地址即可。
     */
    public function resolveTenantRedirectUrl(int $tenantId, string $provider, string $storedRedirect = ''): string
    {
        // 基于租户域名动态拼接（路由前缀 /api/v1）
        $domain = Tenant::where('tenant_id', $tenantId)->value('domain');

        if (! $domain) {
            // 无自定义域名，回退到相对路径（平台域名场景）
            return $storedRedirect ?: "/api/v1/auth/{$provider}/callback";
        }

        $path = $storedRedirect ?: "/api/v1/auth/{$provider}/callback";

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
            throw new ServiceUnavailableException(trans('common.oauth_not_configured', ['provider' => $provider, 'tenant' => $tenantId]));
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
     * 委托模式优先：若租户配置了 oauth_mode=delegated，跳转到认证中心
     * 支付宝使用 RSA2 签名的独立授权流程，不走 Socialite 驱动
     *
     * @param  string  $originDomain  用户来源域名（登录页所在租户域，回调后回跳）
     */
    public function getRedirectUrl(string $provider, int $tenantId, string $originDomain = ''): string
    {
        // 委托模式：跳转到公司认证中心
        $idp = app(IdentityProviderOAuthService::class);
        if ($idp->isConfigured($tenantId)) {
            return $idp->getRedirectUrl($tenantId, $provider, $originDomain);
        }

        if ($provider === 'alipay') {
            return app(AlipayOAuthService::class)->getAuthorizeUrl($tenantId, $originDomain);
        }

        if ($provider === 'wechat_work') {
            return app(WechatWorkOAuthService::class)->getAuthorizeUrl($tenantId, $originDomain);
        }

        if ($provider === 'wechat') {
            return app(WechatOAuthService::class)->getAuthorizeUrl($tenantId, $originDomain);
        }

        // 通用 provider（Socialite）：state 由 Socialite 管理，无法携带来源上下文，
        // 回调后回跳租户默认域名（后台场景域名固定，无影响）
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
     * 委托模式优先：若租户配置了 oauth_mode=delegated，由 IdentityProviderOAuthService 处理
     * 支付宝走独立的 AlipayOAuthService 流程；其余提供商复用 Socialite，
     * 并捕获 InvalidStateException 显式 abort(403)，避免 state 不匹配被静默忽略
     */
    public function handleCallback(string $provider, int $tenantId): array
    {
        // 委托模式：认证中心回调（legacy 带 token；standard 带 code+state 且 provider 为通用 idp）
        $idp = app(IdentityProviderOAuthService::class);
        if ($idp->isConfigured($tenantId) && (request()->has('token') || $provider === 'idp' || request()->has('state'))) {
            return $idp->handleCallback($tenantId, $provider);
        }

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
     *
     * secret 明文回显：调用方需 setting.update 权限，租户管理员可查看
     * 自己配置的凭证以便核对排查（存储层仍加密）。
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
                $corpId = $this->wechatWorkSetting($tenantId, 'corp_id');
                $agentId = $this->wechatWorkSetting($tenantId, 'agent_id');
                $secret = $this->wechatWorkSetting($tenantId, 'secret');
                $redirect = $this->resolveRedirectUrl($tenantId, 'wechat_work', $this->wechatWorkSetting($tenantId, 'redirect'));
                $mode = 'self';

                // 9.4-3：代开发授权租户显示授权记录的真实凭证与回调地址（原读自建值显示空/错）
                $suite = $this->suiteAuthorized($tenantId);
                if ($suite) {
                    $authorization = app(WechatWorkSuiteService::class)->authorization($tenantId);

                    if ($authorization !== null && $authorization->isAuthorized()) {
                        $corpId = $authorization->corp_id;
                        $agentId = (string) ($authorization->agent_id ?? '');
                        $secret = ''; // permanent_code 由套件服务内部使用，永不出库
                        $mode = 'suite';

                        // 回调域：套件模式强制平台统一回调域（服务商代配可信域名）
                        $callbackDomain = config('auth.oauth.callback_domain', '');
                        if ($callbackDomain !== '') {
                            $redirect = "https://{$callbackDomain}/api/v1/auth/wechat_work/callback";
                        }
                    }
                }

                $result[$provider] = [
                    'configured' => app(WechatWorkOAuthService::class)->isConfigured($tenantId),
                    // 启用状态：套件授权即视为启用（双轨之一）；否则读 oauth.wechat_work_enabled 开关
                    'enabled' => $mode === 'suite' || (bool) TenantSetting::get($tenantId, 'oauth', 'wechat_work_enabled', false),
                    'corp_id' => $corpId,
                    'agent_id' => $agentId,
                    'secret' => $secret,
                    'redirect' => $redirect,
                    'mode' => $mode,
                ];

                continue;
            }

            if ($provider === 'wechat') {
                $result[$provider] = [
                    'configured' => app(WechatOAuthService::class)->isConfigured($tenantId),
                    'client_id' => TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', ''),
                    'client_secret' => TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', ''),
                    'redirect' => $this->resolveRedirectUrl($tenantId, 'wechat', TenantSetting::get($tenantId, 'oauth', 'wechat_redirect', '')),
                ];

                continue;
            }

            $config = $this->getOAuthConfig($tenantId, $provider);
            $result[$provider] = [
                'configured' => ! empty($config['client_id']) && ! empty($config['client_secret']),
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'] ?? '',
                'redirect' => $config['redirect'],
            ];
        }

        // IdP 委托模式配置（console 第三方登录页消费）
        $idpBaseUrl = TenantSetting::get($tenantId, 'oauth', 'idp_base_url', '');
        $idpEnabled = TenantSetting::get($tenantId, 'oauth', 'oauth_mode', 'direct') === 'delegated';
        $result['idp'] = [
            'configured' => $idpEnabled && $idpBaseUrl !== '',
            'enabled' => $idpEnabled,
            'base_url' => $idpBaseUrl,
            'protocol' => TenantSetting::get($tenantId, 'oauth', 'idp_protocol', 'standard'),
            'client_id' => TenantSetting::get($tenantId, 'oauth', 'idp_client_id', ''),
            'client_secret' => TenantSetting::get($tenantId, 'oauth', 'idp_client_secret', ''),
            'login_path' => TenantSetting::get($tenantId, 'oauth', 'idp_login_path', ''),
            'redirect_uri' => TenantSetting::get($tenantId, 'oauth', 'idp_redirect_uri', ''),
            'redirect_uri_default' => $this->resolveRedirectUrl($tenantId, '{provider}'),
            'field_mapping' => TenantSetting::get($tenantId, 'oauth', 'idp_field_mapping', ''),
        ];

        return $result;
    }

    /**
     * 检查租户是否已有套件代开发授权（9.2 互斥防御）
     *
     * 防御式：模块未启用 / 表不存在返回 false，与 TenantOAuthController 原检查一致。
     */
    protected function suiteAuthorized(int $tenantId): bool
    {
        if (! class_exists(WechatWorkSuiteService::class)) {
            return false;
        }

        if (! Schema::hasTable('wechat_work_authorizations')) {
            return false;
        }

        $authorization = app(WechatWorkSuiteService::class)->authorization($tenantId);

        return $authorization !== null && $authorization->isAuthorized();
    }

    /**
     * 读取企微租户配置（wechatwork 组优先，旧 oauth.wechat_work_* 回退）
     *
     * 9.6 模块边界后配置组为 wechatwork，展示路径只读不回写（登录路径负责读时迁移）。
     */
    protected function wechatWorkSetting(int $tenantId, string $key, string $default = ''): string
    {
        $new = TenantSetting::get($tenantId, 'wechatwork', $key, null);
        if ($new !== null && $new !== '') {
            return (string) $new;
        }

        return (string) TenantSetting::get($tenantId, 'oauth', "wechat_work_{$key}", $default);
    }

    /**
     * 更新租户 OAuth 配置
     */
    public function updateOAuthConfig(int $tenantId, string $provider, array $config): void
    {
        // idp 委托模式：enabled 开关映射为 oauth_mode，其余字段走通用 idp_* key
        if ($provider === 'idp' && array_key_exists('enabled', $config)) {
            TenantSetting::set($tenantId, 'oauth', 'oauth_mode', ! empty($config['enabled']) ? 'delegated' : 'direct');
            unset($config['enabled']);
        }

        // wechat_work（9.6 模块边界）：enabled 开关写 oauth 组，凭证配置写 wechatwork 组；
        // 9.2 互斥防御下沉：套件已授权时拒绝写自建凭证（防 SaveOAuthConfigTool 等直调绕过控制器）
        if ($provider === 'wechat_work') {
            if (! empty($config['corp_id'] ?? '') && $this->suiteAuthorized($tenantId)) {
                throw new DomainException('当前租户已使用平台代开发应用授权，无需配置自建应用；如需切换请先解除授权');
            }

            foreach ($config as $key => $value) {
                if ($key === 'enabled') {
                    TenantSetting::set($tenantId, 'oauth', 'wechat_work_enabled', ! empty($value));
                    continue;
                }

                if ($key === 'secret' && $value === '********') {
                    continue; // 跳过遮罩占位符
                }

                TenantSetting::set($tenantId, 'wechatwork', $key, $value, $key === 'secret');
            }

            return;
        }

        // wechat_work 使用 corp_id/agent_id/secret 模式，alipay 使用 private_key，非标准 client_id/client_secret
        $sensitiveKeys = match ($provider) {
            'wechat_work' => ['secret'],
            'alipay' => ['private_key'],
            default => ['client_secret'],
        };

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
