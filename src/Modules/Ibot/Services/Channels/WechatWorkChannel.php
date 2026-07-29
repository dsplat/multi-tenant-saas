<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ibot\Contracts\IbotChannelContract;
use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;

/**
 * 企业微信频道（自建应用，webhook 传输——企微唯一选择，docs/ibot.md 第二节）
 *
 * 凭证：credentials.corp_id / corp_secret / agent_id（必填），
 * token / encoding_aes_key（回调验签与加解密）。
 * external_id 为组织内成员 userid；出向经「发送应用消息」API，
 * 按 2048 字节上限自动分段。
 *
 * 坑（设计稿已登记）：企微 API 要求服务器出口 IP 在应用「企业可信 IP」
 * 白名单内，否则调用报 60020 静默失败——sendMessage 已记日志便于排查。
 */
class WechatWorkChannel implements IbotChannelContract
{
    // 企微 text 消息 content 上限 2048 字节（UTF-8），留余量
    private const CHUNK_BYTES = 2000;

    private const API_BASE = 'https://qyapi.weixin.qq.com/cgi-bin';

    /**
     * 解析回调明文事件（XML 已由 webhook 控制器解密并转数组）
     */
    public function parseInbound(Ibot $ibot, array $payload): ?IbotInboundMessage
    {
        if (($payload['MsgType'] ?? '') !== 'text') {
            return null;
        }

        $fromUser = $payload['FromUserName'] ?? null;
        $text = $payload['Content'] ?? null;

        if (! is_string($fromUser) || $fromUser === '' || ! is_string($text) || trim($text) === '') {
            return null;
        }

        return new IbotInboundMessage(
            externalId: $fromUser,
            text: trim($text),
            messageId: isset($payload['MsgId']) ? (string) $payload['MsgId'] : null,
            raw: $payload,
        );
    }

    public function sendMessage(Ibot $ibot, string $externalId, string $text): bool
    {
        $agentId = $ibot->credentials['agent_id'] ?? null;

        if (! $agentId || trim($text) === '') {
            return false;
        }

        $accessToken = $this->accessToken($ibot);

        if ($accessToken === '') {
            return false;
        }

        $ok = true;

        foreach ($this->splitText($text) as $chunk) {
            $response = Http::timeout(15)->post(self::API_BASE . "/message/send?access_token={$accessToken}", [
                'touser' => $externalId,
                'msgtype' => 'text',
                'agentid' => (int) $agentId,
                'text' => ['content' => $chunk],
            ]);

            if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
                Log::warning('[Ibot] 企微 message/send 失败', [
                    'ibot_id' => $ibot->ibot_id,
                    'errcode' => $response->json('errcode'),
                    'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
                ]);
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * 构造该 ibot 的回调加解密器（webhook 控制器验签/解密用）
     */
    public function crypto(Ibot $ibot): WechatWorkCrypto
    {
        return new WechatWorkCrypto(
            (string) ($ibot->credentials['token'] ?? ''),
            (string) ($ibot->credentials['encoding_aes_key'] ?? ''),
            (string) ($ibot->credentials['corp_id'] ?? ''),
        );
    }

    /**
     * access_token 获取（按 ibot 缓存，提前 5 分钟过期）
     */
    private function accessToken(Ibot $ibot): string
    {
        $corpId = $ibot->credentials['corp_id'] ?? '';
        $corpSecret = $ibot->credentials['corp_secret'] ?? '';

        if ($corpId === '' || $corpSecret === '') {
            return '';
        }

        $cacheKey = "ibot:wechat_work:access_token:{$ibot->ibot_id}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::timeout(15)->get(self::API_BASE . '/gettoken', [
            'corpid' => $corpId,
            'corpsecret' => $corpSecret,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[Ibot] 企微 gettoken 失败', [
                'ibot_id' => $ibot->ibot_id,
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
     * 按 UTF-8 字节数分段（不切断多字节字符）
     *
     * @return array<string>
     */
    private function splitText(string $text): array
    {
        if (strlen($text) <= self::CHUNK_BYTES) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (mb_str_split($text) as $char) {
            if (strlen($current) + strlen($char) > self::CHUNK_BYTES) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $char;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
