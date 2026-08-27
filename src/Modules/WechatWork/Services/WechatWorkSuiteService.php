<?php

namespace MultiTenantSaas\Modules\WechatWork\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;

/**
 * 企业微信服务商代开发套件服务
 *
 * 封装服务商侧全部 API（get_provider_token / get_suite_token /
 * get_customized_auth_url / get_permanent_code / get_corp_token），为租户
 * 代开发授权链路与 Auth 模块 WechatWorkOAuthService 双轨凭证提供底层能力。
 *
 * 关键机制：
 * - 代开发模式生成授权二维码用 get_customized_auth_url（provider_access_token
 *   调用，state 仅限 a-zA-Z0-9 且 ≤32 字节）；get_pre_auth_code + 3rdapp/install
 *   是第三方应用模式接口，对 dk 代开发模板调用必报 48002，禁止用于本模式
 * - suite_ticket 由模板回调每 10 分钟推送，换取 suite_access_token 必须
 *   使用最新 ticket，缺票/过期即视为服务商未就绪
 * - permanent_code 充当 secret 角色：corp_access_token = get_corp_token
 *   （suite_access_token + permanent_code），与自建应用 gettoken 平级
 * - 所有 token 缓存提前 5 分钟过期，避免边界超时
 */
class WechatWorkSuiteService
{
    use ManagesOAuthState;

    /**
     * 服务商 API 基础地址
     */
    protected const API_BASE = 'https://qyapi.weixin.qq.com/cgi-bin/service';

    /**
     * 代开发应用授权二维码地址（get_customized_auth_url 返回）
     *
     * 注意：3rdapp/install 是第三方应用模式授权页，代开发模式禁用。
     */
    protected const AUTHORIZE_URL = 'https://open.work.weixin.qq.com/wwopen/customApp/authorize';

    /**
     * suite_ticket 缓存 TTL（企微每 10 分钟推送一次，30 分钟未收到视为失联）
     */
    protected const SUITE_TICKET_TTL = 1800;

    /**
     * suite_access_token / provider_access_token / pre_auth_code / corp_token 有效期（企微 7200s）
     */
    protected const TOKEN_TTL = 7200;

    /**
     * state 使用的 provider 标识（与登录 OAuth 的 wechat_work 区分，独立缓存空间）
     */
    protected const STATE_PROVIDER = 'wechat_work_suite';

    /**
     * 获取当前启用的服务商（单服务商模式，按 ID 升序取第一条）
     */
    public function provider(): ?ServiceProvider
    {
        return ServiceProvider::query()
            ->whereNull('tenant_id')
            ->active()
            ->orderBy('service_provider_id')
            ->first();
    }

    /**
     * 获取服务商，未配置时抛出异常
     *
     * @throws ServiceUnavailableException
     */
    public function requireProvider(): ServiceProvider
    {
        $provider = $this->provider();

        if ($provider === null) {
            throw new ServiceUnavailableException('WechatWork: 平台未配置企微服务商（service_providers 表为空或未启用）');
        }

        return $provider;
    }

    /**
     * 读取 suite_ticket（模板回调写入）
     */
    public function suiteTicket(int $providerId): string
    {
        return (string) Cache::get($this->suiteTicketCacheKey($providerId), '');
    }

    /**
     * 写入 suite_ticket（模板回调 suite_ticket 事件调用）
     */
    public function storeSuiteTicket(int $providerId, string $ticket): void
    {
        Cache::put($this->suiteTicketCacheKey($providerId), $ticket, self::SUITE_TICKET_TTL);
    }

    /**
     * 获取 suite_access_token（带缓存）
     *
     * @throws ServiceUnavailableException 服务商未配置 / suite_ticket 缺失
     */
    public function suiteAccessToken(ServiceProvider $provider): string
    {
        $cacheKey = "wechat_work_suite_token:{$provider->service_provider_id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $ticket = $this->suiteTicket($provider->service_provider_id);
        if ($ticket === '') {
            throw new ServiceUnavailableException(
                'WechatWork: suite_ticket 缺失（请确认模板回调已配置并收到 suite_ticket 推送）'
            );
        }

        $resp = Http::post(self::API_BASE . '/get_suite_token', [
            'suite_id' => $provider->suite_id,
            'suite_secret' => $provider->suite_secret,
            'suite_ticket' => $ticket,
        ]);

        $data = $this->parseResponse($resp, 'get_suite_token');

        // 企微 get_suite_token 成功返回字段为 suite_access_token（非 access_token）
        $token = (string) ($data['suite_access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('WechatWork: empty suite access_token returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? self::TOKEN_TTL);
        Cache::put($cacheKey, $token, $expiresIn - 300);

        return $token;
    }

    /**
     * 获取服务商 access_token（get_provider_token，带缓存）
     *
     * 代开发模式生成授权二维码（get_customized_auth_url）必须使用
     * provider_access_token 调用，与 suite_access_token 是两个独立凭证。
     *
     * @throws ServiceUnavailableException 服务商未配置 provider_secret
     */
    public function providerAccessToken(ServiceProvider $provider): string
    {
        $cacheKey = "wechat_work_provider_token:{$provider->service_provider_id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $corpId = (string) $provider->provider_corp_id;
        $secret = (string) $provider->provider_secret;

        if ($corpId === '' || $secret === '') {
            throw new ServiceUnavailableException(
                'WechatWork: 服务商未配置 provider_corp_id / provider_secret（服务商后台「通用开发参数」）'
            );
        }

        $resp = Http::post(self::API_BASE . '/get_provider_token', [
            'corpid' => $corpId,
            'provider_secret' => $secret,
        ]);

        $data = $this->parseResponse($resp, 'get_provider_token');

        $token = (string) ($data['provider_access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('WechatWork: empty provider access_token returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? self::TOKEN_TTL);
        Cache::put($cacheKey, $token, $expiresIn - 300);

        return $token;
    }

    /**
     * 获取预授权码（带缓存）
     *
     * @deprecated 代开发模式不可用（get_pre_auth_code 是第三方应用接口，对 dk 模板报 48002），
     *             生成授权二维码请用 customizedAuthUrl()。保留仅供第三方应用模式扩展。
     *
     * @throws ServiceUnavailableException
     */
    public function preAuthCode(ServiceProvider $provider): string
    {
        $cacheKey = "wechat_work_pre_auth_code:{$provider->service_provider_id}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $suiteToken = $this->suiteAccessToken($provider);

        $resp = Http::get(self::API_BASE . '/get_pre_auth_code', [
            'suite_access_token' => $suiteToken,
        ]);

        $data = $this->parseResponse($resp, 'get_pre_auth_code');

        $code = (string) ($data['pre_auth_code'] ?? '');
        if ($code === '') {
            throw new ServiceUnavailableException('WechatWork: empty pre_auth_code returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? self::TOKEN_TTL);
        Cache::put($cacheKey, $code, $expiresIn - 300);

        return $code;
    }

    /**
     * 生成租户扫码授权二维码 URL（代开发模式 get_customized_auth_url）
     *
     * state 携带租户前缀 {tenantId}{random}（仅限 a-zA-Z0-9、≤32 字节），
     * 扫码授权完成后无浏览器回跳，state 随 create_auth 事件经
     * get_permanent_code 响应返回，据此恢复租户上下文入库。
     */
    public function buildAuthorizeUrl(int $tenantId): string
    {
        $provider = $this->requireProvider();

        $state = $this->generateCustomizedState($tenantId);

        $resp = Http::post(self::API_BASE . '/get_customized_auth_url?provider_access_token=' . $this->providerAccessToken($provider), [
            'state' => $state,
            'templateid_list' => [$provider->suite_id],
        ]);

        $data = $this->parseResponse($resp, 'get_customized_auth_url');

        $url = (string) ($data['qrcode_url'] ?? '');
        if ($url === '') {
            throw new ServiceUnavailableException('WechatWork: empty qrcode_url returned');
        }

        return $url;
    }

    /**
     * 服务商代开发模板权限清单（[{key, label}]，未知 key 原样展示）
     *
     * 权限集由服务商在平台声明（metadata.template_permissions，对应企微服务商
     * 后台模板勾选的权限点）；企业扫码授权即一次性获得模板全部权限，
     * 可信 IP / 回调域名由服务商代管，使用方无需逐项配置。
     */
    public function templatePermissions(ServiceProvider $provider): array
    {
        $labels = ServiceProvider::TEMPLATE_PERMISSIONS;
        $keys = $provider->metadata['template_permissions'] ?? [];

        return array_values(array_map(
            fn (string $key) => ['key' => $key, 'label' => $labels[$key] ?? $key],
            $keys,
        ));
    }

    /**
     * 生成代开发授权 state：{16 位租户 ID（左补零）}{16 位随机}（纯字母数字，共 32 字节）
     *
     * 企微限定 state 仅 a-zA-Z0-9 且 ≤32 字节；租户 ID 固定 16 位前缀（不足
     * 左补零），回调时经 tenantIdFromState 恢复租户上下文。
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
        // 代开发格式：{16 位租户 ID}{16 位随机}
        if (preg_match('/^(\d{16})[a-zA-Z0-9]{0,16}$/', $state, $m)) {
            return (int) $m[1];
        }

        // 第三方应用格式：{tenantId}.{random}
        if (preg_match('/^(\d{4,20})\./', $state, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * 校验授权 state（一次性，验证后即删），供 create_auth 回调恢复租户上下文
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException state 无效时 403
     */
    public function verifyAuthorizationState(string $state, int $tenantId): array
    {
        return $this->verifyState($state, $tenantId, self::STATE_PROVIDER);
    }

    /**
     * 测试服务商凭证连通性（admin 后台连接测试，不落缓存）
     *
     * @return array{access_token: string, expires_in: int}
     *
     * @throws ServiceUnavailableException 凭证错误或 suite_ticket 缺失
     */
    public function testSuiteToken(ServiceProvider $provider): array
    {
        $ticket = $this->suiteTicket($provider->service_provider_id);
        if ($ticket === '') {
            throw new ServiceUnavailableException(
                'suite_ticket 缺失：请确认模板回调已配置（' . ($provider->callback_url ?: '回调 URL 未填') . '）且已收到企微推送'
            );
        }

        $resp = Http::post(self::API_BASE . '/get_suite_token', [
            'suite_id' => $provider->suite_id,
            'suite_secret' => $provider->suite_secret,
            'suite_ticket' => $ticket,
        ]);

        $data = $this->parseResponse($resp, 'get_suite_token');

        // 企微 get_suite_token 成功返回字段为 suite_access_token（非 access_token）
        $token = (string) ($data['suite_access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('WechatWork: empty suite access_token returned');
        }

        return [
            'access_token' => substr($token, 0, 8) . '…',
            'expires_in' => (int) ($data['expires_in'] ?? self::TOKEN_TTL),
        ];
    }

    /**
     * 用 auth_code 换取永久授权码（get_permanent_code）
     *
     * @return array{corp_id: string, permanent_code: string, agent_id: string, corp_name: string, state: string}
     *         state：代开发模式扫描带参二维码授权时原样返回（可恢复租户上下文）
     *
     * @throws ServiceUnavailableException
     */
    public function exchangePermanentCode(ServiceProvider $provider, string $authCode): array
    {
        $suiteToken = $this->suiteAccessToken($provider);

        $resp = Http::post(self::API_BASE . '/get_permanent_code?suite_access_token=' . $suiteToken, [
            'auth_code' => $authCode,
        ]);

        $data = $this->parseResponse($resp, 'get_permanent_code');

        $corpId = (string) ($data['auth_corp_info']['corpid'] ?? '');
        $permanentCode = (string) ($data['permanent_code'] ?? '');
        $agentId = (string) ($data['auth_info']['agent'][0]['agentid'] ?? '');
        $corpName = (string) ($data['auth_corp_info']['corp_name'] ?? '');

        if ($corpId === '' || $permanentCode === '') {
            throw new ServiceUnavailableException('WechatWork: get_permanent_code 响应缺少 corp_id / permanent_code');
        }

        return [
            'corp_id' => $corpId,
            'permanent_code' => $permanentCode,
            'agent_id' => $agentId,
            'corp_name' => $corpName,
            'state' => (string) ($data['state'] ?? ''),
        ];
    }

    /**
     * 获取企业 access_token（代开发模式，permanent_code 充当 secret）
     *
     * 与自建应用 gettoken 平级，供 WechatWorkOAuthService 双轨适配调用，
     * 缓存提前 5 分钟过期。
     *
     * @throws ServiceUnavailableException 租户未授权 / 服务商未配置
     */
    public function corpAccessToken(int $tenantId): string
    {
        $cacheKey = "wechat_work_corp_token:{$tenantId}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $authorization = $this->authorization($tenantId);

        if ($authorization === null || ! $authorization->isAuthorized()) {
            throw new ServiceUnavailableException("WechatWork: tenant {$tenantId} 未完成企微代开发授权");
        }

        $provider = ServiceProvider::query()->find($authorization->service_provider_id);
        if ($provider === null) {
            throw new ServiceUnavailableException('WechatWork: 服务商记录不存在（service_provider_id=' . $authorization->service_provider_id . '）');
        }

        $suiteToken = $this->suiteAccessToken($provider);

        $resp = Http::post(self::API_BASE . '/get_corp_token?suite_access_token=' . $suiteToken, [
            'auth_corpid' => $authorization->corp_id,
            'permanent_code' => $authorization->permanent_code,
        ]);

        $data = $this->parseResponse($resp, 'get_corp_token');

        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('WechatWork: empty corp access_token returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? self::TOKEN_TTL);
        Cache::put($cacheKey, $token, $expiresIn - 300);

        return $token;
    }

    /**
     * 查询租户代开发授权记录
     */
    public function authorization(int $tenantId): ?WechatWorkAuthorization
    {
        return WechatWorkAuthorization::query()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * 生成代开发应用回调 URL（「开始代开发应用」时填入企微服务商后台）
     *
     * 路径带租户标识（/suite/cz/{tenantId}），回调到达后直接定位租户的
     * 应用级回调凭证，无需按 corp_id 反查；兼容手填 URL（无标识）时
     * 控制器按各租户凭证遍历验签。
     */
    public function appCallbackUrl(int $tenantId): string
    {
        return $this->callbackDomain() . '/api/v1/wechat-work/suite/cz/' . $tenantId;
    }

    /**
     * 解析应用回调候选授权记录
     *
     * tenantId 给定 → 该租户单条；null → 全部已授权记录（回调 URL 未带
     * 租户标识时按各自凭证遍历验签匹配）。回调运行在平台域（无租户上下文），
     * 显式豁免 TenantScope（同 markRevokedByCorpId 先例）。
     *
     * @return WechatWorkAuthorization[]
     */
    public function appAuthorizations(?int $tenantId): array
    {
        return TenantScope::allowUnscoped(fn () => WechatWorkAuthorization::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', WechatWorkAuthorization::STATUS_AUTHORIZED)
            ->orderBy('authorization_id')
            ->get()
            ->all());
    }

    /**
     * 幂等保存租户授权（create_auth 事件 / 授权回调兜底均走此入口）
     *
     * 授权回跳运行在平台统一回调域（无租户上下文），TenantScope fail-closed
     * 会拦截 updateOrCreate 的查询分支（已授权租户重复回调时退化为 create
     * 撞 UNIQUE(tenant_id)），故显式豁免作用域，租户由参数 + creating 事件保证。
     */
    public function saveAuthorization(int $tenantId, int $providerId, array $data): WechatWorkAuthorization
    {
        $attributes = [
            'service_provider_id' => $providerId,
            'corp_id' => $data['corp_id'],
            'agent_id' => $data['agent_id'] ?? null,
            'permanent_code' => $data['permanent_code'],
            'status' => WechatWorkAuthorization::STATUS_AUTHORIZED,
            'authorized_at' => now(),
            'revoked_at' => null,
        ];

        // 应用级回调凭证可选透传（admin 回填时更新）
        foreach (['app_callback_token', 'app_encoding_aes_key', 'app_callback_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        return TenantScope::allowUnscoped(fn () => WechatWorkAuthorization::updateOrCreate(
            ['tenant_id' => $tenantId],
            $attributes
        ));
    }

    /**
     * 取消授权（cancel_auth 事件）：按被授权企业 ID 标记全部记录为 revoked
     *
     * 套件回调运行在平台域（无租户上下文），TenantScope fail-closed 会
     * 将查询拦截为 WHERE 1=0，导致企业侧解绑永远无法同步，故显式豁免
     * （corp_id 企业级全局唯一，跨租户按企业标记符合企微语义）。
     */
    public function markRevokedByCorpId(string $corpId): int
    {
        return TenantScope::allowUnscoped(fn () => WechatWorkAuthorization::query()
            ->where('corp_id', $corpId)
            ->where('status', WechatWorkAuthorization::STATUS_AUTHORIZED)
            ->update([
                'status' => WechatWorkAuthorization::STATUS_REVOKED,
                'revoked_at' => now(),
            ]));
    }

    /**
     * 解析企业微信 API 响应
     *
     * @throws ServiceUnavailableException 当 errcode != 0
     */
    protected function parseResponse($resp, string $api): array
    {
        if (! $resp->successful()) {
            Log::error('[WechatWorkSuiteService] HTTP failed', [
                'api' => $api,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            throw new ServiceUnavailableException("WechatWork API request failed: HTTP {$resp->status()}");
        }

        $data = $resp->json();
        // 注意：企微 get_suite_token 成功响应无 errcode 字段（仅 suite_access_token/expires_in），
        // 无 errcode 一律视为成功；错误响应才带 errcode（如 60020/40013）
        $errCode = $data['errcode'] ?? 0;

        if ($errCode !== 0) {
            $errMsg = $data['errmsg'] ?? 'unknown error';
            Log::error('[WechatWorkSuiteService] API error', [
                'api' => $api,
                'errcode' => $errCode,
                'errmsg' => $errMsg,
            ]);
            throw new ServiceUnavailableException("WechatWork API error [{$errCode}]: {$errMsg}");
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
     * suite_ticket 缓存 key
     */
    protected function suiteTicketCacheKey(int $providerId): string
    {
        return "wechat_work_suite_ticket:{$providerId}";
    }
}
