<?php

declare(strict_types=1);

namespace MultiTenantSaas\EnterpriseWechat;

use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Support\WechatWork\WechatWorkCrypto;

class EnterpriseWechatProvider implements ChannelContract
{
    protected string $corpId;

    protected string $corpSecret;

    protected string $agentId;

    protected WechatWorkCrypto $crypto;

    protected WechatWorkApiClient $apiClient;

    /**
     * @param  array<string, string>  $config
     */
    public function __construct(array $config)
    {
        $this->corpId = $config['corp_id'] ?? '';
        $this->corpSecret = $config['corp_secret'] ?? '';
        $this->agentId = $config['agent_id'] ?? '';
        $this->crypto = new WechatWorkCrypto(
            $config['token'] ?? '',
            $config['encoding_aes_key'] ?? '',
            $this->corpId,
        );
        $this->apiClient = new WechatWorkApiClient($this->corpId, $this->corpSecret, $this->agentId);
    }

    /**
     * 验证回调请求签名.
     *
     * 共享 crypto 4 元验签；无 encrypt 时空串不影响排序拼接，等价 3 元验签。
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, array<string>>  $headers
     */
    public function verifyWebhook(array $query, array $headers): bool
    {
        return $this->crypto->verifySignature(
            (string) ($query['msg_signature'] ?? ''),
            (string) ($query['timestamp'] ?? ''),
            (string) ($query['nonce'] ?? ''),
            (string) ($query['encrypt'] ?? ''),
        );
    }

    /**
     * 处理接收到的消息.
     *
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    public function onMessage(array $rawMessage): array
    {
        $encrypt = $rawMessage['encrypt'] ?? '';

        if ($encrypt !== '') {
            $decrypted = $this->crypto->decrypt((string) $encrypt);
            if ($decrypted !== null && $decrypted !== '') {
                $xml = simplexml_load_string($decrypted, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml !== false) {
                    $rawMessage = json_decode(json_encode($xml), true) ?? $rawMessage;
                }
            }
        }

        $msgType = $rawMessage['MsgType'] ?? 'text';

        return match ($msgType) {
            'text' => $this->parseTextMessage($rawMessage),
            'image' => $this->parseImageMessage($rawMessage),
            'voice' => $this->parseVoiceMessage($rawMessage),
            'video', 'shortvideo' => $this->parseVideoMessage($rawMessage),
            'location' => $this->parseLocationMessage($rawMessage),
            'link' => $this->parseLinkMessage($rawMessage),
            'event' => $this->parseEventMessage($rawMessage),
            default => [],
        };
    }

    /**
     * 发送消息到企业微信.
     */
    public function sendMessage(string $toUser, array $message): bool
    {
        return $this->apiClient->sendMessage($toUser, $message);
    }

    /**
     * 获取会话参与者.
     *
     * @return array<int, string>
     */
    public function getParticipants(string $conversationId): array
    {
        return [];
    }

    /**
     * 获取会话信息.
     *
     * @return array<string, mixed>
     */
    public function getConversationInfo(string $conversationId): array
    {
        return [
            'conversation_id' => $conversationId,
            'channel' => 'enterprise_wechat',
        ];
    }

    /**
     * 获取访问令牌（委托共享 API 客户端，token 按 corp+agent 缓存）.
     */
    public function getAccessToken(): string
    {
        return $this->apiClient->accessToken();
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseTextMessage(array $rawMessage): array
    {
        return [
            'type' => 'text',
            'content' => (string) ($rawMessage['Content'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseImageMessage(array $rawMessage): array
    {
        return [
            'type' => 'image',
            'media_id' => (string) ($rawMessage['MediaId'] ?? ''),
            'pic_url' => (string) ($rawMessage['PicUrl'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseVoiceMessage(array $rawMessage): array
    {
        return [
            'type' => 'voice',
            'media_id' => (string) ($rawMessage['MediaId'] ?? ''),
            'format' => (string) ($rawMessage['Format'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseVideoMessage(array $rawMessage): array
    {
        return [
            'type' => 'video',
            'media_id' => (string) ($rawMessage['MediaId'] ?? ''),
            'thumb_media_id' => (string) ($rawMessage['ThumbMediaId'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseLocationMessage(array $rawMessage): array
    {
        return [
            'type' => 'location',
            'latitude' => (string) ($rawMessage['Latitude'] ?? ''),
            'longitude' => (string) ($rawMessage['Longitude'] ?? ''),
            'scale' => (string) ($rawMessage['Scale'] ?? ''),
            'label' => (string) ($rawMessage['Label'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseLinkMessage(array $rawMessage): array
    {
        return [
            'type' => 'link',
            'title' => (string) ($rawMessage['Title'] ?? ''),
            'description' => (string) ($rawMessage['Description'] ?? ''),
            'url' => (string) ($rawMessage['Url'] ?? ''),
            'pic_url' => (string) ($rawMessage['PicUrl'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseEventMessage(array $rawMessage): array
    {
        return [
            'type' => 'event',
            'event' => (string) ($rawMessage['Event'] ?? ''),
            'event_key' => (string) ($rawMessage['EventKey'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];
    }
}
