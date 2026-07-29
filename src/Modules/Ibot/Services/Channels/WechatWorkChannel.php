<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Channels;

use MultiTenantSaas\Modules\Ibot\Contracts\IbotChannelContract;
use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Support\WechatWork\WechatWorkCrypto;

/**
 * 企业微信频道（自建应用，webhook 传输——企微唯一选择，docs/ibot.md 第二节）
 *
 * 渠道语义层：消息进哪、服务谁（parseInbound / 出向分段）；
 * 验签加解密与 API 调用委托共享 SDK 层 src/Support/WechatWork/。
 *
 * 凭证：credentials.corp_id / corp_secret / agent_id（必填），
 * token / encoding_aes_key（回调验签与加解密）。
 * external_id 为组织内成员 userid；出向经「发送应用消息」API，
 * 按 2048 字节上限自动分段。
 */
class WechatWorkChannel implements IbotChannelContract
{
    // 企微 text 消息 content 上限 2048 字节（UTF-8），留余量
    private const CHUNK_BYTES = 2000;

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
        if (! ($ibot->credentials['agent_id'] ?? null) || trim($text) === '') {
            return false;
        }

        $client = $this->apiClient($ibot);
        $ok = true;

        foreach ($this->splitText($text) as $chunk) {
            $ok = $client->sendText($externalId, $chunk) && $ok;
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
     * 构造该 ibot 的 API 客户端（token 按 corp+agent 缓存，与 Channel 模块共享）
     */
    private function apiClient(Ibot $ibot): WechatWorkApiClient
    {
        return new WechatWorkApiClient(
            (string) ($ibot->credentials['corp_id'] ?? ''),
            (string) ($ibot->credentials['corp_secret'] ?? ''),
            (string) ($ibot->credentials['agent_id'] ?? ''),
        );
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
