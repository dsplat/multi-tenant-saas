<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Contracts\IdGeneratorContract;

class WechatOfficialProvider implements ChannelContract
{
    protected string $appId;
    protected string $appSecret;
    protected string $token;
    protected string $encodingAesKey;

    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {
        $config = config('channel.providers.wechat_official', []);
        $this->appId = $config['app_id'] ?? '';
        $this->appSecret = $config['app_secret'] ?? '';
        $this->token = $config['token'] ?? '';
        $this->encodingAesKey = $config['encoding_aes_key'] ?? '';
    }

    public function verifyWebhook(array $query, array $headers): bool
    {
        $signature = $query['signature'] ?? $query['msg_signature'] ?? '';
        $timestamp = $query['timestamp'] ?? '';
        $nonce = $query['nonce'] ?? '';

        $tmpArr = [$this->token, $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $expected = sha1(implode('', $tmpArr));

        return $expected === $signature;
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    public function onMessage(array $rawMessage): array
    {
        $tenantId = TenantContext::getId();
        $msgType = $rawMessage['MsgType'] ?? 'text';

        $base = [
            'tenant_id' => $tenantId,
            'from_user' => (string) ($rawMessage['FromUserName'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? 0),
        ];

        return match ($msgType) {
            'text' => array_merge($base, [
                'type' => 'text',
                'content' => (string) ($rawMessage['Content'] ?? ''),
                'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            ]),
            'image' => array_merge($base, [
                'type' => 'image',
                'media_id' => (string) ($rawMessage['MediaId'] ?? ''),
                'pic_url' => (string) ($rawMessage['PicUrl'] ?? ''),
                'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            ]),
            'voice' => array_merge($base, [
                'type' => 'voice',
                'media_id' => (string) ($rawMessage['MediaId'] ?? ''),
                'format' => (string) ($rawMessage['Format'] ?? ''),
                'recognition' => (string) ($rawMessage['Recognition'] ?? ''),
                'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            ]),
            'video', 'shortvideo' => array_merge($base, [
                'type' => 'video',
                'media_id' => (string) ($rawMessage['MediaId'] ?? ''),
                'thumb_media_id' => (string) ($rawMessage['ThumbMediaId'] ?? ''),
                'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            ]),
            'location' => array_merge($base, [
                'type' => 'location',
                'latitude' => (string) ($rawMessage['Latitude'] ?? ''),
                'longitude' => (string) ($rawMessage['Longitude'] ?? ''),
                'scale' => (string) ($rawMessage['Scale'] ?? ''),
                'label' => (string) ($rawMessage['Label'] ?? ''),
                'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            ]),
            'link' => array_merge($base, [
                'type' => 'link',
                'title' => (string) ($rawMessage['Title'] ?? ''),
                'description' => (string) ($rawMessage['Description'] ?? ''),
                'url' => (string) ($rawMessage['Url'] ?? ''),
                'pic_url' => (string) ($rawMessage['PicUrl'] ?? ''),
                'msg_id' => (string) ($rawMessage['MsgId'] ?? ''),
            ]),
            'event' => array_merge($base, [
                'type' => 'event',
                'event' => (string) ($rawMessage['Event'] ?? ''),
                'event_key' => (string) ($rawMessage['EventKey'] ?? ''),
                'ticket' => (string) ($rawMessage['Ticket'] ?? ''),
            ]),
            default => [],
        };
    }

    public function sendMessage(string $conversationId, array $message): bool
    {
        $tenantId = TenantContext::getId();
        $accessToken = $this->getAccessToken($tenantId);

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge([
            'touser' => $conversationId,
            'msgtype' => $message['msgtype'] ?? 'text',
        ], $message);

        $response = Http::post(
            "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token={$accessToken}",
            $payload,
        );

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('WechatOfficialProvider: send message failed', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'response' => $result,
            ]);

            return false;
        }

        return true;
    }

    public function getParticipants(string $conversationId): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConversationInfo(string $conversationId): array
    {
        $tenantId = TenantContext::getId();

        return [
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
            'channel' => 'wechat_official',
        ];
    }

    public function getAccessToken(?string $tenantId = null): string
    {
        $cacheKey = "wechat_official:access_token:{$this->appId}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::get('https://api.weixin.qq.com/cgi-bin/token', [
            'grant_type' => 'client_credential',
            'appid' => $this->appId,
            'secret' => $this->appSecret,
        ]);

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('WechatOfficialProvider: get access token failed', [
                'tenant_id' => $tenantId,
                'response' => $result,
            ]);

            return '';
        }

        $token = $result['access_token'] ?? '';
        $expiresIn = (int) ($result['expires_in'] ?? 7200);

        cache()->put($cacheKey, $token, $expiresIn - 300);

        return (string) $token;
    }

    public function replyText(string $toUser, string $fromUser, string $content): string
    {
        return <<<XML
        <xml>
        <ToUserName><![CDATA[{$toUser}]]></ToUserName>
        <FromUserName><![CDATA[{$fromUser}]]></FromUserName>
        <CreateTime>{time()}</CreateTime>
        <MsgType><![CDATA[text]]></MsgType>
        <Content><![CDATA[{$content}]]></Content>
        </xml>
        XML;
    }
}
