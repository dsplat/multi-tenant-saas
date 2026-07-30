<?php

namespace MultiTenantSaas\Support\WechatWork;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 企业微信服务端 API 客户端（共享 SDK 层）
 *
 * access_token 按 corp+agent 缓存（同一自建应用的 ibot 与 Channel 共享令牌），
 * 提前 5 分钟过期。后续 media/kf 等 API 在此扩展。
 *
 * 坑：企微 API 要求服务器出口 IP 在应用「企业可信 IP」白名单内，
 * 否则调用报 60020——失败均记日志便于排查。
 */
class WechatWorkApiClient
{
    private const API_BASE = 'https://qyapi.weixin.qq.com/cgi-bin';

    public function __construct(
        private readonly string $corpId,
        private readonly string $corpSecret,
        private readonly string $agentId = '',
    ) {}

    /**
     * 获取 access_token（缓存优先）
     */
    public function accessToken(): string
    {
        if ($this->corpId === '' || $this->corpSecret === '') {
            return '';
        }

        $cacheKey = "wechat_work:access_token:{$this->corpId}:{$this->agentId}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::timeout(15)->get(self::API_BASE . '/gettoken', [
            'corpid' => $this->corpId,
            'corpsecret' => $this->corpSecret,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] gettoken 失败', [
                'corp_id' => $this->corpId,
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

    /**
     * 发送应用消息（自动补 touser/agentid）
     *
     * @param  array<string, mixed>  $message  msgtype 及对应内容体
     */
    public function sendMessage(string $toUser, array $message): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge([
            'touser' => $toUser,
            'msgtype' => $message['msgtype'] ?? 'text',
            'agentid' => (int) $this->agentId,
        ], $message);

        $response = Http::timeout(15)->post(self::API_BASE . "/message/send?access_token={$accessToken}", $payload);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] message/send 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 发送文本应用消息
     */
    public function sendText(string $toUser, string $content): bool
    {
        return $this->sendMessage($toUser, [
            'msgtype' => 'text',
            'text' => ['content' => $content],
        ]);
    }

    /**
     * 发送 markdown 应用消息（仅企业微信客户端渲染，content 上限 4096 字节）
     */
    public function sendMarkdown(string $toUser, string $content): bool
    {
        return $this->sendMessage($toUser, [
            'msgtype' => 'markdown',
            'markdown' => ['content' => $content],
        ]);
    }
}
