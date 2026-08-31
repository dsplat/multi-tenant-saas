<?php

namespace MultiTenantSaas\Support\Wechat;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信服务端 API 客户端（共享 SDK 层）
 *
 * 面向公众号/小程序开放接口（网页授权、用户信息），component 模式下
 * token 由外部解析器提供（WechatComponentService::componentAccessToken），
 * 与企微 WechatWorkApiClient 的 tokenResolver 双轨设计对齐。
 *
 * 坑：微信第三方平台 API 要求服务器出口 IP 在平台「IP 白名单」内，
 * 否则 component_access_token 获取报 61004；网页授权接口失败均记日志。
 */
class WechatApiClient
{
    private const API_BASE = 'https://api.weixin.qq.com/cgi-bin';

    private const SNS_BASE = 'https://api.weixin.qq.com/sns';

    public function __construct(
        private readonly string $appId,
        private readonly ?\Closure $componentTokenResolver = null,
        private readonly ?string $componentAppId = null,
        private readonly ?string $proxy = null,
    ) {}

    /**
     * 构造微信 API 请求（统一超时 + 出口代理注入）
     */
    private function http(int $timeout = 15): PendingRequest
    {
        $request = Http::timeout($timeout);

        if ($this->proxy !== null && $this->proxy !== '') {
            $request = $request->withOptions(['proxy' => $this->proxy]);
        }

        return $request;
    }

    /**
     * component 模式网页授权 code 换取用户身份（sns/oauth2/component/access_token）
     *
     * 公众号授权给第三方平台后，网页授权由第三方平台代替实现（公众号无需
     * 配置网页授权域名），换取结果与自建模式同构（openid/unionid）。
     *
     * @return array<string, mixed> 微信原始返回（openid / unionid / scope 等）
     */
    public function getUserByCode(string $code): array
    {
        if ($this->componentTokenResolver === null || $this->componentAppId === null) {
            return [];
        }

        $componentToken = (string) call_user_func($this->componentTokenResolver);

        if ($componentToken === '') {
            return [];
        }

        $response = $this->http()->get(self::SNS_BASE . '/oauth2/component/access_token', [
            'appid' => $this->appId,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'component_appid' => $this->componentAppId,
            'component_access_token' => $componentToken,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? 0) !== 0) {
            Log::warning('[Wechat] sns/oauth2/component/access_token 失败', [
                'appid' => $this->appId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return [];
        }

        return $response->json();
    }

    /**
     * 网页授权用户信息（sns/userinfo，需 snsapi_userinfo 授权）
     *
     * @return array<string, mixed> 微信原始返回（openid/nickname/headimgurl/unionid 等）
     */
    public function getUserInfo(string $accessToken, string $openid): array
    {
        if ($accessToken === '' || $openid === '') {
            return [];
        }

        $response = $this->http()->get(self::SNS_BASE . '/userinfo', [
            'access_token' => $accessToken,
            'openid' => $openid,
            'lang' => 'zh_CN',
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? 0) !== 0) {
            Log::warning('[Wechat] sns/userinfo 失败', [
                'openid' => $openid,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return [];
        }

        return $response->json();
    }

    /**
     * 获取公众号 access_token（自建模式 gettoken，预留）
     *
     * component 模式请走 tokenResolver（componentAccessToken 换取
     * authorizer_access_token 的 api_authorizer_token 链路）。
     */
    public function accessToken(string $secret): string
    {
        if ($this->appId === '' || $secret === '') {
            return '';
        }

        $cacheKey = "wechat:access_token:{$this->appId}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = $this->http()->get(self::API_BASE . '/token', [
            'grant_type' => 'client_credential',
            'appid' => $this->appId,
            'secret' => $secret,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? 0) !== 0) {
            Log::warning('[Wechat] token 失败', [
                'appid' => $this->appId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return '';
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 7200);

        cache()->put($cacheKey, $token, max($expiresIn - 300, 60));

        return $token;
    }
}
