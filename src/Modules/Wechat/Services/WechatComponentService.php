<?php

namespace MultiTenantSaas\Modules\Wechat\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Scopes\TenantScope;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 微信第三方平台（服务商模式）组件服务
 *
 * 封装第三方平台侧全部 API（api_component_token / api_create_preauthcode /
 * api_query_auth / api_authorizer_token），为租户授权链路与 Auth 模块
 * WechatOAuthService 双轨凭证提供底层能力。
 *
 * 关键机制（对齐 WechatWorkSuiteService）：
 * - component_verify_ticket 由平台回调每 10 分钟推送（12h 有效），换取
 *   component_access_token 必须使用最新 ticket，缺票/过期即视为组件未就绪
 * - component_access_token 有效期 7200s，pre_auth_code 有效期 1800s，
 *   均缓存并提前过期，避免边界超时
 * - authorizer_refresh_token 不解除授权即永久有效，充当 secret 角色；
 *   authorizer_access_token 经 api_authorizer_token 换取（7200s）
 * - 授权回跳为浏览器重定向（平台域 authorize-callback，带 auth_code），
 *   与企微 create_auth 事件带 auth_code 的形态不同；authorized 事件仅通知
 * - 微信服务商不能主动解除授权：unauthorized 事件（AuthorizerAppid）到达后
 *   按 authorizer_appid 标记 revoked，租户侧解除仅本地标记 + 引导后台取消
 */
class WechatComponentService
{
    use ManagesOAuthState;

    /**
     * 第三方平台 API 基础地址
     */
    protected const API_BASE = 'https://api.weixin.qq.com/cgi-bin';

    /**
     * 授权发起页地址：
     * - PC 版：mp.weixin.qq.com/cgi-bin/componentloginpage
     * - H5 版：open.weixin.qq.com/wxaopen/safe/bindcomponent（#wechat_redirect）
     * 均需携带 component_appid + pre_auth_code + redirect_uri + auth_type
     */
    protected const AUTHORIZE_URL_PC = 'https://mp.weixin.qq.com/cgi-bin/componentloginpage';

    protected const AUTHORIZE_URL_H5 = 'https://open.weixin.qq.com/wxaopen/safe/bindcomponent';

    /**
     * component_verify_ticket 缓存 TTL（微信每 10 分钟推送一次、12h 有效，
     * 存 1 小时，超 12h 未更新视为组件失联需平台后台重置推送）
     */
    protected const VERIFY_TICKET_TTL = 3600;

    /**
     * component_access_token / authorizer_access_token 有效期（微信 7200s）
     */
    protected const TOKEN_TTL = 7200;

    /**
     * state 使用的 provider 标识（与登录 OAuth 的 wechat 区分，独立缓存空间）
     */
    protected const STATE_PROVIDER = 'wechat_component';

    /**
     * 获取当前启用的组件（单组件模式，按 ID 升序取第一条）
     */
    public function provider(): ?ComponentProvider
    {
        return ComponentProvider::query()
            ->whereNull('tenant_id')
            ->active()
            ->orderBy('component_provider_id')
            ->first();
    }

    /**
     * 获取组件，未配置时抛出异常
     *
     * @throws ServiceUnavailableException
     */
    public function requireProvider(): ComponentProvider
    {
        $provider = $this->provider();

        if ($provider === null) {
            throw new ServiceUnavailableException('Wechat: 平台未配置微信第三方平台组件（wechat_component_providers 表为空或未启用）');
        }

        return $provider;
    }

    /**
     * 读取 component_verify_ticket（组件回调写入）
     */
    public function componentVerifyTicket(int $providerId): string
    {
        return (string) Cache::get($this->verifyTicketCacheKey($providerId), '');
    }

    /**
     * 写入 component_verify_ticket（回调 component_verify_ticket 事件调用）
     */
    public function storeComponentVerifyTicket(int $providerId, string $ticket): void
    {
        Cache::put($this->verifyTicketCacheKey($providerId), $ticket, self::VERIFY_TICKET_TTL);
    }

    /**
     * 获取 component_access_token（带缓存）
     *
     * @throws ServiceUnavailableException 组件未配置 / verify_ticket 缺失
     */
    public function componentAccessToken(ComponentProvider $provider): string
    {
        $cacheKey = "wechat_component_token:{$provider->component_provider_id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $ticket = $this->componentVerifyTicket($provider->component_provider_id);
        if ($ticket === '') {
            throw new ServiceUnavailableException(
                'Wechat: component_verify_ticket 缺失（请确认第三方平台「消息与事件接收URL」已配置并收到 verify_ticket 推送）'
            );
        }

        $resp = Http::post(self::API_BASE . '/component/api_component_token', [
            'component_appid' => $provider->component_appid,
            'component_appsecret' => $provider->component_secret,
            'component_verify_ticket' => $ticket,
        ]);

        $data = $this->parseResponse($resp, 'api_component_token');

        $token = (string) ($data['component_access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('Wechat: empty component_access_token returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? self::TOKEN_TTL);
        Cache::put($cacheKey, $token, max($expiresIn - 300, 60));

        return $token;
    }

    /**
     * 获取预授权码（带缓存）
     *
     * pre_auth_code 有效期 1800s，授权链接必须在有效期内完成授权。
     *
     * @throws ServiceUnavailableException
     */
    public function preAuthCode(ComponentProvider $provider): string
    {
        $cacheKey = "wechat_pre_auth_code:{$provider->component_provider_id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $componentToken = $this->componentAccessToken($provider);

        $resp = Http::post(
            self::API_BASE . '/component/api_create_preauthcode?component_access_token=' . $componentToken,
            ['component_appid' => $provider->component_appid]
        );

        $data = $this->parseResponse($resp, 'api_create_preauthcode');

        $code = (string) ($data['pre_auth_code'] ?? '');
        if ($code === '') {
            throw new ServiceUnavailableException('Wechat: empty pre_auth_code returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 1800);
        Cache::put($cacheKey, $code, max($expiresIn - 60, 60));

        return $code;
    }

    /**
     * 组装微信第三方平台授权页 URL（launch 端点内部使用）
     *
     * state 由调用方预生成（buildLaunchUrl 生成并写防重放缓存），此处原样透传——
     * 授权回跳 /authorize/callback 校验的就是这份 state。
     *
     * @param  string  $state  32 位授权 state（16 位租户左补零 + 16 位随机）
     * @param  string  $authType  1=公众号 2=小程序 3=都展示（默认）
     * @param  string  $mode  pc=PC 授权页 / h5=手机端授权页
     */
    public function buildAuthorizeUrl(string $state, string $authType = '3', string $mode = 'pc'): string
    {
        $provider = $this->requireProvider();

        $preAuthCode = $this->preAuthCode($provider);

        $redirectUri = $this->authorizeCallbackUrl();

        $base = $mode === 'h5' ? self::AUTHORIZE_URL_H5 : self::AUTHORIZE_URL_PC;

        $url = $base . '?' . http_build_query([
            'component_appid' => $provider->component_appid,
            'pre_auth_code' => $preAuthCode,
            'redirect_uri' => $redirectUri,
            'auth_type' => $authType,
            'state' => $state,
        ]);

        // H5 授权页需微信内置浏览器打开
        if ($mode === 'h5') {
            $url .= '#wechat_redirect';
        }

        return $url;
    }

    /**
     * 生成统一认证域授权发起 URL（/authorize/launch，浏览器直接访问）
     *
     * 微信第三方平台「授权发起页域名」仅允许 1 个且校验跳转来源：租户 console /
     * H5 终端页面（含租户自定义域名）不直接打开微信授权页，先跳本端点（平台域
     * auth.neihang.com），由端点 302 到微信授权页——跳转来源恒为平台域，租户
     * 任意域名均可发起授权。state 在此生成并写防重放缓存，随 URL 透传至微信
     * 授权页，回跳时经 tenantIdFromState 恢复租户上下文。
     */
    public function buildLaunchUrl(int $tenantId, string $authType = '3', string $mode = 'pc'): string
    {
        $state = $this->generateCustomizedState($tenantId);

        return $this->callbackDomain() . '/api/v1/wechat/authorize/launch?'
            . http_build_query(['state' => $state, 'auth_type' => $authType, 'mode' => $mode]);
    }

    /**
     * 用 auth_code 换取授权信息（api_query_auth）
     *
     * @return array{authorizer_appid: string, authorizer_access_token: string,
     *               authorizer_refresh_token: string, auth_type: string, nickname: string, head_img: string}
     *
     * @throws ServiceUnavailableException
     */
    public function exchangeAuthorization(ComponentProvider $provider, string $authCode): array
    {
        $componentToken = $this->componentAccessToken($provider);

        $resp = Http::post(
            self::API_BASE . '/component/api_query_auth?component_access_token=' . $componentToken,
            [
                'component_appid' => $provider->component_appid,
                'authorization_code' => $authCode,
            ]
        );

        $data = $this->parseResponse($resp, 'api_query_auth');

        $info = $data['authorization_info'] ?? [];
        $appid = (string) ($info['authorizer_appid'] ?? '');
        $refreshToken = (string) ($info['authorizer_refresh_token'] ?? '');

        if ($appid === '' || $refreshToken === '') {
            throw new ServiceUnavailableException('Wechat: api_query_auth 响应缺少 authorizer_appid / authorizer_refresh_token');
        }

        return [
            'authorizer_appid' => $appid,
            'authorizer_access_token' => (string) ($info['authorizer_access_token'] ?? ''),
            'authorizer_refresh_token' => $refreshToken,
            'auth_type' => (string) ($info['auth_type'] ?? ''),
            'nickname' => (string) ($info['authorizer_info']['nick_name'] ?? ''),
            'head_img' => (string) ($info['authorizer_info']['head_img'] ?? ''),
        ];
    }

    /**
     * 获取被授权账号 access_token（api_authorizer_token，带缓存）
     *
     * authorizer_refresh_token 充当 secret 角色，换取 authorizer_access_token
     * 供网页授权（sns/oauth2/component/access_token 的 component_access_token
     * 参数独立，此处 token 用于公众号业务 API）。
     *
     * @throws ServiceUnavailableException 租户未授权 / 组件未配置
     */
    public function authorizerAccessToken(Authorization $authorization): string
    {
        $cacheKey = "wechat_authorizer_token:{$authorization->authorization_id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $provider = ComponentProvider::query()->find($authorization->component_provider_id);
        if ($provider === null) {
            throw new ServiceUnavailableException('Wechat: 组件记录不存在（component_provider_id=' . $authorization->component_provider_id . '）');
        }

        $componentToken = $this->componentAccessToken($provider);

        $resp = Http::post(
            self::API_BASE . '/component/api_authorizer_token?component_access_token=' . $componentToken,
            [
                'component_appid' => $provider->component_appid,
                'authorizer_appid' => $authorization->authorizer_appid,
                'authorizer_refresh_token' => $authorization->authorizer_refresh_token,
            ]
        );

        $data = $this->parseResponse($resp, 'api_authorizer_token');

        $token = (string) ($data['authorizer_access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('Wechat: empty authorizer_access_token returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? self::TOKEN_TTL);
        Cache::put($cacheKey, $token, max($expiresIn - 300, 60));

        return $token;
    }

    /**
     * 第三方平台权限清单（[{key, label}]，未知 key 原样展示）
     *
     * 权限集由平台在开放平台第三方平台后台配置，授权页按权限集展示；
     * 记录于 metadata.permissions，租户授权即一次性获得组件全部权限。
     */
    public function templatePermissions(ComponentProvider $provider): array
    {
        $labels = ComponentProvider::TEMPLATE_PERMISSIONS;
        $keys = $provider->metadata['permissions'] ?? [];

        return array_values(array_map(
            fn (string $key) => ['key' => $key, 'label' => $labels[$key] ?? $key],
            $keys,
        ));
    }

    /**
     * 生成第三方平台授权 state：{16 位租户 ID（左补零）}{16 位随机}（纯字母数字，共 32 字节）
     *
     * 与企微代开发授权同构：租户 ID 固定 16 位前缀（不足左补零），
     * 授权回跳经 tenantIdFromState 恢复租户上下文。
     */
    protected function generateCustomizedState(int $tenantId, array $context = []): string
    {
        $state = str_pad((string) $tenantId, 16, '0', STR_PAD_LEFT) . Str::random(16);
        $key = $this->stateCacheKey($state, $tenantId, self::STATE_PROVIDER);

        Cache::put($key, $context ?: true, $this->stateTtl);

        return $state;
    }

    /**
     * 从授权 state 解析租户 ID（兼容纯字母数字与旧点号两种格式）
     */
    public function tenantIdFromState(string $state): ?int
    {
        // 第三方平台格式：{16 位租户 ID}{16 位随机}
        if (preg_match('/^(\d{16})[a-zA-Z0-9]{0,16}$/', $state, $m)) {
            return (int) $m[1];
        }

        // 登录 OAuth 格式：{tenantId}.{random}
        if (preg_match('/^(\d{4,20})\./', $state, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * 校验授权 state（一次性，验证后即删），供授权回跳恢复租户上下文
     *
     * @throws HttpException state 无效时 403
     */
    public function verifyAuthorizationState(string $state, int $tenantId): array
    {
        return $this->verifyState($state, $tenantId, self::STATE_PROVIDER);
    }

    /**
     * 测试组件凭证连通性（admin 后台连接测试，不落缓存）
     *
     * @return array{access_token: string, expires_in: int}
     *
     * @throws ServiceUnavailableException 凭证错误或 verify_ticket 缺失
     */
    public function testComponentToken(ComponentProvider $provider): array
    {
        $ticket = $this->componentVerifyTicket($provider->component_provider_id);
        if ($ticket === '') {
            throw new ServiceUnavailableException(
                'component_verify_ticket 缺失：请确认第三方平台「消息与事件接收URL」已配置（' . ($provider->callback_url ?: '回调 URL 未填') . '）且已收到推送'
            );
        }

        $resp = Http::post(self::API_BASE . '/component/api_component_token', [
            'component_appid' => $provider->component_appid,
            'component_appsecret' => $provider->component_secret,
            'component_verify_ticket' => $ticket,
        ]);

        $data = $this->parseResponse($resp, 'api_component_token');

        $token = (string) ($data['component_access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('Wechat: empty component_access_token returned');
        }

        return [
            'access_token' => substr($token, 0, 8) . '…',
            'expires_in' => (int) ($data['expires_in'] ?? self::TOKEN_TTL),
        ];
    }

    /**
     * 查询租户授权记录
     */
    public function authorization(int $tenantId): ?Authorization
    {
        // 显式按 tenant_id 查询（绕过 TenantScope）：webhook/后台无租户上下文亦安全
        return TenantScope::allowUnscoped(fn () => Authorization::query()
            ->where('tenant_id', $tenantId)
            ->first());
    }

    /**
     * 幂等保存租户授权（授权回跳 / Job 换码均走此入口）
     *
     * 授权回跳运行在平台统一回调域（无租户上下文），TenantScope fail-closed
     * 会拦截 updateOrCreate 的查询分支（已授权租户重复回调时退化为 create
     * 撞 UNIQUE(tenant_id)），故显式豁免作用域，租户由参数 + creating 事件保证。
     */
    public function saveAuthorization(int $tenantId, int $providerId, array $data): Authorization
    {
        $attributes = [
            'component_provider_id' => $providerId,
            'authorizer_appid' => $data['authorizer_appid'],
            'authorizer_type' => $data['authorizer_type'] ?? Authorization::TYPE_OFFICIAL_ACCOUNT,
            'authorizer_refresh_token' => $data['authorizer_refresh_token'],
            'status' => Authorization::STATUS_AUTHORIZED,
            'authorized_at' => now(),
            'revoked_at' => null,
        ];

        // 账号元信息可选透传
        foreach (['nickname', 'head_img'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $attributes[$field] = $data[$field];
            }
        }

        return TenantScope::allowUnscoped(fn () => Authorization::updateOrCreate(
            ['tenant_id' => $tenantId],
            $attributes
        ));
    }

    /**
     * 取消授权（unauthorized 事件）：按被授权账号 AppID 标记 revoked
     *
     * 组件回调运行在平台域（无租户上下文），TenantScope fail-closed 会
     * 将查询拦截为 WHERE 1=0，导致公众号侧取消授权永远无法同步，故显式豁免
     * （authorizer_appid 全局唯一，跨租户按账号标记符合微信语义）。
     */
    public function markRevokedByAuthorizerAppid(string $appid): int
    {
        return TenantScope::allowUnscoped(fn () => Authorization::query()
            ->where('authorizer_appid', $appid)
            ->where('status', Authorization::STATUS_AUTHORIZED)
            ->update([
                'status' => Authorization::STATUS_REVOKED,
                'revoked_at' => now(),
            ]));
    }

    /**
     * 探测微信侧授权是否仍有效（api_authorizer_token + 存量 refresh_token）
     *
     * 微信服务商无主动解除授权 API：公众号管理员在公众平台取消授权后
     * 推送 unauthorized 事件；事件丢失时本地状态会与微信侧分裂。此探测
     * 用于状态对账（status 端点）与解除授权引导（revoke 端点）。
     *
     * @return bool|null true=微信侧仍授权；false=微信侧已解除（61003 未授权关系 /
     *                   40013 无效 appid 等业务错误码）；null=探测失败（网络/异常），状态未知
     */
    public function isStillAuthorizedOnWechat(Authorization $auth): ?bool
    {
        if ($auth->authorizer_appid === '' || empty($auth->authorizer_refresh_token)) {
            return null;
        }

        try {
            $provider = $this->requireProvider();
            $componentToken = $this->componentAccessToken($provider);

            $resp = Http::post(
                self::API_BASE . '/component/api_authorizer_token?component_access_token=' . $componentToken,
                [
                    'component_appid' => $provider->component_appid,
                    'authorizer_appid' => $auth->authorizer_appid,
                    'authorizer_refresh_token' => $auth->authorizer_refresh_token,
                ]
            );

            if (! $resp->successful()) {
                Log::warning('[WechatComponent] 授权状态探测 HTTP 失败', [
                    'tenant_id' => $auth->tenant_id,
                    'authorizer_appid' => $auth->authorizer_appid,
                    'status' => $resp->status(),
                ]);

                return null;
            }

            $data = $resp->json();
            $errCode = (int) ($data['errcode'] ?? 0);

            if ($errCode === 0) {
                return true;
            }

            Log::info('[WechatComponent] 微信侧确认已解除授权', [
                'tenant_id' => $auth->tenant_id,
                'authorizer_appid' => $auth->authorizer_appid,
                'errcode' => $errCode,
                'errmsg' => (string) ($data['errmsg'] ?? ''),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('[WechatComponent] 授权状态探测失败', [
                'tenant_id' => $auth->tenant_id,
                'authorizer_appid' => $auth->authorizer_appid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 平台授权回调 URL（第三方平台「授权回调域名」配置，平台域）
     *
     * 微信授权页完成授权后浏览器重定向至此，带 auth_code + state。
     */
    public function authorizeCallbackUrl(): string
    {
        return $this->callbackDomain() . '/api/v1/wechat/authorize/callback';
    }

    /**
     * 平台消息与事件接收 URL（第三方平台「消息与事件接收URL」配置，平台域）
     */
    public function callbackUrl(): string
    {
        return $this->callbackDomain() . '/api/v1/wechat/message/callback';
    }

    /**
     * 解析微信 API 响应
     *
     * @throws ServiceUnavailableException 当 errcode != 0
     */
    protected function parseResponse($resp, string $api): array
    {
        if (! $resp->successful()) {
            Log::error('[WechatComponentService] HTTP failed', [
                'api' => $api,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            throw new ServiceUnavailableException("Wechat API request failed: HTTP {$resp->status()}");
        }

        $data = $resp->json();
        $errCode = (int) ($data['errcode'] ?? 0);

        if ($errCode !== 0) {
            $errMsg = (string) ($data['errmsg'] ?? 'unknown error');
            Log::error('[WechatComponentService] API error', [
                'api' => $api,
                'errcode' => $errCode,
                'errmsg' => $errMsg,
            ]);
            throw new ServiceUnavailableException("Wechat API error [{$errCode}]: {$errMsg}");
        }

        return $data;
    }

    /**
     * 平台统一回调域（OAUTH_CALLBACK_DOMAIN），未配置回退 app.url
     */
    protected function callbackDomain(): string
    {
        $domain = (string) config('auth.oauth.callback_domain', '');

        if ($domain !== '') {
            return 'https://' . $domain;
        }

        return rtrim((string) config('app.url'), '/');
    }

    /**
     * component_verify_ticket 缓存 key
     */
    protected function verifyTicketCacheKey(int $providerId): string
    {
        return "wechat_component_verify_ticket:{$providerId}";
    }
}
