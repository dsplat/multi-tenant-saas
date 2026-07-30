<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel\Providers;

use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\DTOs\InboundMessage;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Support\WechatWork\WechatWorkCrypto;

/**
 * 企业微信自建应用驱动（内部：单聊 + 群聊）
 *
 * 协议：XML 加密回调（验签 + AES 解密，复用共享 WechatWorkCrypto）。
 * - 单聊：成员 ↔ 应用，接收文本/图片等，发送走 message/send。
 * - 群聊：应用所在内部群（回调带 ChatId），建/维会话；发送走 appchat/send（chatid）。
 *   注：应用回调不推送群聊消息正文，群聊消息入库需会话存档（Phase 2 接收策略）。
 *
 * 凭证（tenant_settings group=channel key=enterprise_wechat_app）：
 *   corp_id / corp_secret / agent_id / token / encoding_aes_key
 */
class EnterpriseWechatAppDriver implements ChannelContract
{
    public const TYPE = 'enterprise_wechat_app';

    private const SENDER_INTERNAL = 'internal';

    private const SENDER_EXTERNAL = 'external';

    private readonly string $corpId;

    private readonly WechatWorkCrypto $crypto;

    private readonly WechatWorkApiClient $apiClient;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->corpId = (string) ($config['corp_id'] ?? '');

        $this->crypto = new WechatWorkCrypto(
            (string) ($config['token'] ?? ''),
            (string) ($config['encoding_aes_key'] ?? ''),
            $this->corpId,
        );

        $this->apiClient = new WechatWorkApiClient(
            $this->corpId,
            (string) ($config['corp_secret'] ?? ''),
            (string) ($config['agent_id'] ?? ''),
        );
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function verifyUrl(array $query): ?string
    {
        return $this->crypto->verifyUrl(
            (string) ($query['msg_signature'] ?? ''),
            (string) ($query['timestamp'] ?? ''),
            (string) ($query['nonce'] ?? ''),
            (string) ($query['echostr'] ?? ''),
        );
    }

    public function verifySignature(array $query, string $rawBody): bool
    {
        return $this->crypto->verifySignature(
            (string) ($query['msg_signature'] ?? ''),
            (string) ($query['timestamp'] ?? ''),
            (string) ($query['nonce'] ?? ''),
            $this->extractEncrypt($rawBody),
        );
    }

    public function parseInbound(string $rawBody, array $query): ?InboundMessage
    {
        $payload = $this->decryptBody($rawBody);

        if ($payload === null) {
            return null;
        }

        // 群聊回调带 ChatId（事件/消息均然）；应用回调不推送群消息正文，仅建/维会话
        $chatId = $this->str($payload['ChatId'] ?? '');

        if ($chatId !== '') {
            return new InboundMessage(
                channel: self::TYPE,
                conversationType: 'group',
                externalConvId: $chatId,
                senderExternalId: $this->str($payload['FromUserName'] ?? ''),
                senderType: self::SENDER_INTERNAL,
                msgType: 'event',
                content: '',
                platformMsgId: null,
                conversationTitle: $this->str($payload['Name'] ?? '') ?: null,
                raw: $payload,
            );
        }

        return $this->parseDirectMessage($payload);
    }

    public function sendMessage(Conversation $conversation, array $message): bool
    {
        $metadata = $conversation->metadata ?? [];

        if ($conversation->type === 'group') {
            $chatId = (string) ($metadata['external_conv_id'] ?? '');

            return $chatId !== '' && $this->apiClient->sendGroupMessage($chatId, $message);
        }

        $toUser = (string) ($metadata['external_conv_id'] ?? '');

        return $toUser !== '' && $this->apiClient->sendMessage($toUser, $message);
    }

    /**
     * 解析单聊消息（文本及其它媒体类型；事件/未知类型返回 null 仅 ACK）。
     */
    private function parseDirectMessage(array $payload): ?InboundMessage
    {
        $msgType = $this->str($payload['MsgType'] ?? '');
        $fromUser = $this->str($payload['FromUserName'] ?? '');

        if ($fromUser === '' || $msgType === '' || $msgType === 'event') {
            return null;
        }

        $content = match ($msgType) {
            'text' => $this->str($payload['Content'] ?? ''),
            'image' => $this->str($payload['PicUrl'] ?? ''),
            'voice' => $this->str($payload['MediaId'] ?? ''),
            'video', 'shortvideo' => $this->str($payload['MediaId'] ?? ''),
            'location' => $this->str($payload['Label'] ?? ''),
            'link' => $this->str($payload['Url'] ?? ''),
            default => '',
        };

        return new InboundMessage(
            channel: self::TYPE,
            conversationType: 'direct',
            externalConvId: $fromUser,
            senderExternalId: $fromUser,
            senderType: self::SENDER_INTERNAL,
            msgType: $msgType,
            content: $content,
            platformMsgId: isset($payload['MsgId']) ? (string) $payload['MsgId'] : null,
            raw: $payload,
        );
    }

    /**
     * 解密回调体：XML 取 Encrypt 密文 → AES 解密 → 明文 XML 转数组。
     *
     * @return array<string, mixed>|null
     */
    private function decryptBody(string $rawBody): ?array
    {
        $encrypt = $this->extractEncrypt($rawBody);

        if ($encrypt === '') {
            return null;
        }

        $plain = $this->crypto->decrypt($encrypt);

        if ($plain === null || $plain === '') {
            return null;
        }

        return $this->xmlToArray($plain);
    }

    /**
     * 从回调 XML body 提取 Encrypt 密文。
     */
    private function extractEncrypt(string $rawBody): string
    {
        if (trim($rawBody) === '') {
            return '';
        }

        $xml = @simplexml_load_string($rawBody, 'SimpleXMLElement', LIBXML_NOCDATA);

        return $xml !== false ? (string) ($xml->Encrypt ?? '') : '';
    }

    /**
     * 安全取字符串值（SimpleXML + json_encode 空 CDATA 会产生空数组）。
     */
    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function xmlToArray(string $xml): ?array
    {
        $parsed = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($parsed === false) {
            return null;
        }

        $array = json_decode((string) json_encode($parsed), true);

        return is_array($array) ? $array : null;
    }
}
