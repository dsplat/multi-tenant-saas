<?php

declare(strict_types=1);

namespace MultiTenantSaas\EnterpriseWechat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ChannelContract;

class EnterpriseWechatProvider implements ChannelContract
{
    protected string $corpId;
    protected string $corpSecret;
    protected string $agentId;
    protected SignatureValidator $signatureValidator;

    /**
     * @param  array<string, string>  $config
     */
    public function __construct(array $config)
    {
        $this->corpId = $config['corp_id'] ?? '';
        $this->corpSecret = $config['corp_secret'] ?? '';
        $this->agentId = $config['agent_id'] ?? '';
        $this->signatureValidator = new SignatureValidator(
            $config['token'] ?? '',
            $config['encoding_aes_key'] ?? '',
        );
    }

    /**
     * 验证回调请求签名.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, array<string>>  $headers
     */
    public function verifyWebhook(array $query, array $headers): bool
    {
        $signature = $query['msg_signature'] ?? '';
        $timestamp = $query['timestamp'] ?? '';
        $nonce = $query['nonce'] ?? '';
        $encrypt = $query['encrypt'] ?? '';

        if ($encrypt !== '') {
            return $this->signatureValidator->validateMsgSignature(
                ['timestamp' => $timestamp, 'nonce' => $nonce, 'encrypt' => $encrypt],
                (string) $signature,
            );
        }

        return $this->signatureValidator->validateSignature(
            ['timestamp' => $timestamp, 'nonce' => $nonce],
            (string) $signature,
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
            $decrypted = $this->signatureValidator->decryptMessage((string) $encrypt);
            if ($decrypted !== '') {
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
        $accessToken = $this->getAccessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge([
            'touser' => $toUser,
            'msgtype' => $message['msgtype'] ?? 'text',
            'agentid' => (int) $this->agentId,
        ], $message);

        $response = Http::post(
            "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token={$accessToken}",
            $payload,
        );

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('EnterpriseWechat: send message failed', ['response' => $result]);

            return false;
        }

        return true;
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
     * 获取访问令牌.
     */
    public function getAccessToken(): string
    {
        $cacheKey = "enterprise_wechat:access_token:{$this->corpId}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::get('https://qyapi.weixin.qq.com/cgi-bin/gettoken', [
            'corpid' => $this->corpId,
            'corpsecret' => $this->corpSecret,
        ]);

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('EnterpriseWechat: get access token failed', ['response' => $result]);

            return '';
        }

        $token = $result['access_token'] ?? '';
        $expiresIn = (int) ($result['expires_in'] ?? 7200);

        cache()->put($cacheKey, $token, $expiresIn - 300);

        return (string) $token;
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
