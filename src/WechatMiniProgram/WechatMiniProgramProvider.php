<?php

declare(strict_types=1);

namespace MultiTenantSaas\WechatMiniProgram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ChannelContract;

class WechatMiniProgramProvider implements ChannelContract
{
    protected string $appId;
    protected string $appSecret;
    protected SignatureValidator $signatureValidator;

    /**
     * @param  array<string, string>  $config
     */
    public function __construct(array $config)
    {
        $this->appId = $config['app_id'] ?? '';
        $this->appSecret = $config['app_secret'] ?? '';
        $this->signatureValidator = new SignatureValidator($config['token'] ?? '');
    }

    public function verifyWebhook(array $query, array $headers): bool
    {
        $signature = $query['signature'] ?? '';
        $timestamp = $query['timestamp'] ?? '';
        $nonce = $query['nonce'] ?? '';

        return $this->signatureValidator->validateSignature(
            ['timestamp' => $timestamp, 'nonce' => $nonce],
            (string) $signature,
        );
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    public function onMessage(array $rawMessage): array
    {
        $msgType = $rawMessage['MsgType'] ?? $rawMessage['msgtype'] ?? 'text';

        return match ($msgType) {
            'text' => $this->parseTextMessage($rawMessage),
            'image' => $this->parseImageMessage($rawMessage),
            'link' => $this->parseLinkMessage($rawMessage),
            'miniprogrampage' => $this->parseMiniProgramPageMessage($rawMessage),
            'event' => $this->parseEventMessage($rawMessage),
            default => $this->parseDefaultMessage($rawMessage),
        };
    }

    public function sendMessage(string $toUser, array $message): bool
    {
        $accessToken = $this->getAccessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge([
            'touser' => $toUser,
            'msgtype' => $message['msgtype'] ?? 'text',
        ], $message);

        $response = Http::post(
            "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token={$accessToken}",
            $payload,
        );

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('WechatMiniProgram: send message failed', ['response' => $result]);

            return false;
        }

        return true;
    }

    public function sendText(string $toUser, string $content): bool
    {
        return $this->sendMessage($toUser, [
            'msgtype' => 'text',
            'text' => ['content' => $content],
        ]);
    }

    public function sendImage(string $toUser, string $mediaId): bool
    {
        return $this->sendMessage($toUser, [
            'msgtype' => 'image',
            'image' => ['media_id' => $mediaId],
        ]);
    }

    public function sendMiniProgramPage(string $toUser, string $title, string $pagePath, string $thumbMediaId): bool
    {
        return $this->sendMessage($toUser, [
            'msgtype' => 'miniprogrampage',
            'miniprogrampage' => [
                'title' => $title,
                'pagepath' => $pagePath,
                'thumb_media_id' => $thumbMediaId,
            ],
        ]);
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
        return [
            'conversation_id' => $conversationId,
            'channel' => 'wechat_mini_program',
        ];
    }

    public function getAccessToken(): string
    {
        $cacheKey = "wechat_mini_program:access_token:{$this->appId}";
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
            Log::error('WechatMiniProgram: get access token failed', ['response' => $result]);

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
            'content' => (string) ($rawMessage['Content'] ?? $rawMessage['content'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? $rawMessage['from_username'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? $rawMessage['to_username'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? $rawMessage['msg_id'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? $rawMessage['create_time'] ?? 0),
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
            'media_id' => (string) ($rawMessage['MediaId'] ?? $rawMessage['media_id'] ?? ''),
            'pic_url' => (string) ($rawMessage['PicUrl'] ?? $rawMessage['pic_url'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? $rawMessage['from_username'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? $rawMessage['to_username'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? $rawMessage['msg_id'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? $rawMessage['create_time'] ?? 0),
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
            'title' => (string) ($rawMessage['Title'] ?? $rawMessage['title'] ?? ''),
            'description' => (string) ($rawMessage['Description'] ?? $rawMessage['description'] ?? ''),
            'url' => (string) ($rawMessage['Url'] ?? $rawMessage['url'] ?? ''),
            'pic_url' => (string) ($rawMessage['PicUrl'] ?? $rawMessage['pic_url'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? $rawMessage['from_username'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? $rawMessage['to_username'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? $rawMessage['msg_id'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? $rawMessage['create_time'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseMiniProgramPageMessage(array $rawMessage): array
    {
        return [
            'type' => 'miniprogrampage',
            'title' => (string) ($rawMessage['Title'] ?? $rawMessage['title'] ?? ''),
            'appid' => (string) ($rawMessage['AppId'] ?? $rawMessage['appid'] ?? ''),
            'pagepath' => (string) ($rawMessage['PagePath'] ?? $rawMessage['pagepath'] ?? ''),
            'thumb_url' => (string) ($rawMessage['ThumbUrl'] ?? $rawMessage['thumb_url'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? $rawMessage['from_username'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? $rawMessage['to_username'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? $rawMessage['msg_id'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? $rawMessage['create_time'] ?? 0),
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
            'event' => (string) ($rawMessage['Event'] ?? $rawMessage['event'] ?? ''),
            'event_key' => (string) ($rawMessage['EventKey'] ?? $rawMessage['event_key'] ?? ''),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? $rawMessage['from_username'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? $rawMessage['to_username'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? $rawMessage['create_time'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    protected function parseDefaultMessage(array $rawMessage): array
    {
        return [
            'type' => (string) ($rawMessage['MsgType'] ?? $rawMessage['msgtype'] ?? 'unknown'),
            'from_user' => (string) ($rawMessage['FromUserName'] ?? $rawMessage['from_username'] ?? ''),
            'to_user' => (string) ($rawMessage['ToUserName'] ?? $rawMessage['to_username'] ?? ''),
            'msg_id' => (string) ($rawMessage['MsgId'] ?? $rawMessage['msg_id'] ?? ''),
            'create_time' => (int) ($rawMessage['CreateTime'] ?? $rawMessage['create_time'] ?? 0),
        ];
    }
}
