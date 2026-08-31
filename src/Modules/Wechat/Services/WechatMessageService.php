<?php

namespace MultiTenantSaas\Modules\Wechat\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageLog;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageTemplate;

/**
 * 服务号消息服务（模板消息 / 客服消息）
 *
 * 凭证双轨对齐登录双轨（回调域三分支铁律）：
 * - component（第三方平台授权）：复用 WechatComponentService::authorizerAccessToken
 *   （api_authorizer_token 换取，authorizer_refresh_token 充当 secret）
 * - self（自建应用）：公众号 AppID/Secret 换 cgi-bin/token（缓存 7000s，提前刷新）
 *
 * 能力边界（微信侧校验，服务端不做二次判断，失败码落日志）：
 * - 模板消息 / 客服消息仅认证服务号可用（订阅号报 48001 类权限错误）
 * - 客服消息需用户 48 小时内与公众号有互动
 * - 模板消息日调用上限 10 万次（粉丝量级提升）
 * - 每次发送落 wechat_message_logs，errmsg/errcode 完整保留供排障
 */
class WechatMessageService
{
    /**
     * 公众号业务 API 基础地址（access_token 挂在 query，与登录 sns/oauth2 不同轨）
     */
    protected const API_BASE = 'https://api.weixin.qq.com/cgi-bin';

    /**
     * self 模式 access_token 缓存 TTL（微信 7200s 有效，提前 200s 刷新，
     * 与 WechatComponentService 提前 300s 的策略同构）
     */
    protected const ACCESS_TOKEN_TTL = 7000;

    public function __construct(
        private readonly WechatComponentService $component,
    ) {}

    // ==================================================================
    // 双轨 access_token
    // ==================================================================

    /**
     * 获取公众号业务 access_token（component 授权优先，self 兜底）
     *
     * @throws ServiceUnavailableException 双轨均未配置 / 微信侧错误
     */
    public function accessToken(int $tenantId): string
    {
        $authorization = $this->component->authorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            return $this->component->authorizerAccessToken($authorization);
        }

        return $this->selfAccessToken($tenantId);
    }

    /**
     * 当前租户消息凭证模式（console status 端点使用，不发起网络请求）
     */
    public function credentialMode(int $tenantId): string
    {
        $authorization = $this->component->authorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            return 'component';
        }

        $appId = TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', '');

        return ($appId !== '' && $secret !== '') ? 'self' : 'none';
    }

    /**
     * self 模式 access_token（cgi-bin/token，带缓存）
     *
     * @throws ServiceUnavailableException 自建凭证未配置
     */
    protected function selfAccessToken(int $tenantId): string
    {
        $appId = TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', '');
        $secret = TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', '');

        if ($appId === '' || $secret === '') {
            throw new ServiceUnavailableException(
                trans('common.oauth_not_configured', ['provider' => 'wechat', 'tenant' => $tenantId])
            );
        }

        $cacheKey = "wechat_msg_token:{$tenantId}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $resp = Http::get(self::API_BASE . '/token', [
            'grant_type' => 'client_credential',
            'appid' => $appId,
            'secret' => $secret,
        ]);

        $data = $this->parseResponse($resp, 'token');

        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new ServiceUnavailableException('Wechat: empty access_token returned');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 7200);
        Cache::put($cacheKey, $token, max($expiresIn - 200, 60));

        return $token;
    }

    // ==================================================================
    // 模板消息
    // ==================================================================

    /**
     * 发送模板消息（按业务标识取微信模板 ID）
     *
     * @param  array<string, string>  $data  模板参数（key → 值，服务端包装为 {value: ...}）
     * @param  string|null  $url  跳转链接（可选，微信要求 http/https 链接）
     *
     * @return array{success: bool, msgid?: string, log_id: int, errcode?: int, errmsg?: string}
     */
    public function sendTemplate(int $tenantId, string $openid, string $templateKey, array $data, ?string $url = null): array
    {
        $template = WechatMessageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('template_key', $templateKey)
            ->active()
            ->first();

        if ($template === null) {
            throw new ServiceUnavailableException("Wechat: 模板登记不存在（template_key={$templateKey}）");
        }

        $payload = [
            'touser' => $openid,
            'template_id' => $template->template_id,
            'data' => collect($data)->map(fn ($value) => ['value' => (string) $value])->all(),
        ];

        if ($url !== null && $url !== '') {
            $payload['url'] = $url;
        }

        return $this->dispatch($tenantId, WechatMessageLog::TYPE_TEMPLATE, $openid, $payload, $templateKey);
    }

    // ==================================================================
    // 客服消息
    // ==================================================================

    /**
     * 发送客服文本消息（需用户 48 小时内与公众号有互动，微信侧校验）
     */
    public function sendCustomText(int $tenantId, string $openid, string $content): array
    {
        return $this->sendCustom($tenantId, $openid, 'text', ['content' => $content]);
    }

    /**
     * 发送客服消息（通用：text/image/voice/video/news/miniprogrampage 等）
     *
     * @param  array<string, mixed>  $payload  消息体（如 text => ['content' => '...'] 的对应字段）
     */
    public function sendCustom(int $tenantId, string $openid, string $msgType, array $payload): array
    {
        $body = [
            'touser' => $openid,
            'msgtype' => $msgType,
            $msgType => $payload,
        ];

        return $this->dispatch($tenantId, WechatMessageLog::TYPE_CUSTOM, $openid, $body);
    }

    // ==================================================================
    // openid 解析
    // ==================================================================

    /**
     * 解析用户在本租户微信（非企微）侧绑定的 openid
     *
     * 以最新绑定记录为准；未绑定返回 null（调用方决定是否拒绝发送）。
     */
    public function openidOfUser(int $tenantId, int $userId): ?string
    {
        return OauthAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('provider', 'like', 'wechat:%')
            ->orderByDesc('oauth_account_id')
            ->value('openid');
    }

    // ==================================================================
    // 内部
    // ==================================================================

    /**
     * 统一发送入口：调用微信 API + 落发送记录
     *
     * 微信业务错误（errcode != 0）不抛异常：记录失败日志并返回结构结果，
     * 供 console 测试发送展示明确错误、程序化调用方按需处理。
     */
    protected function dispatch(int $tenantId, string $messageType, string $openid, array $payload, ?string $templateKey = null): array
    {
        $token = $this->accessToken($tenantId);

        $endpoint = $messageType === WechatMessageLog::TYPE_TEMPLATE
            ? '/message/template/send'
            : '/message/custom/send';

        $resp = Http::post(self::API_BASE . $endpoint . '?access_token=' . $token, $payload);

        $log = new WechatMessageLog;
        $log->tenant_id = $tenantId;
        $log->message_type = $messageType;
        $log->template_key = $templateKey;
        $log->openid = $openid;
        $log->content = $payload;
        $log->save();

        if (! $resp->successful()) {
            $log->status = WechatMessageLog::STATUS_FAILED;
            $log->error_code = (string) $resp->status();
            $log->error_message = 'HTTP ' . $resp->status() . ': ' . substr($resp->body(), 0, 500);
            $log->save();

            Log::error('[WechatMessage] HTTP failed', [
                'tenant_id' => $tenantId,
                'message_type' => $messageType,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return ['success' => false, 'errcode' => $resp->status(), 'errmsg' => $log->error_message, 'log_id' => (int) $log->message_log_id];
        }

        $data = $resp->json();
        $errCode = (int) ($data['errcode'] ?? 0);

        if ($errCode !== 0) {
            $errMsg = (string) ($data['errmsg'] ?? 'unknown error');
            $log->status = WechatMessageLog::STATUS_FAILED;
            $log->error_code = (string) $errCode;
            $log->error_message = $errMsg;
            $log->save();

            Log::warning('[WechatMessage] 微信侧拒绝发送', [
                'tenant_id' => $tenantId,
                'message_type' => $messageType,
                'template_key' => $templateKey,
                'errcode' => $errCode,
                'errmsg' => $errMsg,
            ]);

            return ['success' => false, 'errcode' => $errCode, 'errmsg' => $errMsg, 'log_id' => (int) $log->message_log_id];
        }

        $log->status = WechatMessageLog::STATUS_SUCCESS;
        $log->msg_id = (string) ($data['msgid'] ?? '');
        $log->sent_at = now();
        $log->save();

        return ['success' => true, 'msgid' => $log->msg_id, 'log_id' => (int) $log->message_log_id];
    }

    /**
     * 解析微信 API 响应（errcode != 0 抛异常，供 token 类请求使用）
     */
    protected function parseResponse($resp, string $api): array
    {
        if (! $resp->successful()) {
            Log::error('[WechatMessageService] HTTP failed', [
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
            Log::error('[WechatMessageService] API error', [
                'api' => $api,
                'errcode' => $errCode,
                'errmsg' => $errMsg,
            ]);
            throw new ServiceUnavailableException("Wechat API error [{$errCode}]: {$errMsg}");
        }

        return $data;
    }
}
