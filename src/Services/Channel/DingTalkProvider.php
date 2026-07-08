<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ChannelContract;

class DingTalkProvider implements ChannelContract
{
    protected string $appKey;
    protected string $appSecret;

    public function __construct()
    {
        $config = config('channel.providers.dingtalk', []);
        $this->appKey = $config['app_key'] ?? '';
        $this->appSecret = $config['app_secret'] ?? '';
    }

    /**
     * @param  array<string, mixed>  $rawMessage
     * @return array<string, mixed>
     */
    public function onMessage(array $rawMessage): array
    {
        $tenantId = TenantContext::getId();
        $msgType = $rawMessage['msgtype'] ?? 'text';

        $base = [
            'tenant_id' => $tenantId,
            'conversation_id' => (string) ($rawMessage['conversationId'] ?? ''),
            'sender_id' => (string) ($rawMessage['senderId'] ?? ''),
            'sender_nick' => (string) ($rawMessage['senderNick'] ?? ''),
            'create_time' => (int) ($rawMessage['createAt'] ?? 0),
        ];

        return match ($msgType) {
            'text' => array_merge($base, [
                'type' => 'text',
                'content' => (string) ($rawMessage['text']['content'] ?? ''),
            ]),
            'image' => array_merge($base, [
                'type' => 'image',
                'media_id' => (string) ($rawMessage['image']['media_id'] ?? ''),
            ]),
            'voice' => array_merge($base, [
                'type' => 'voice',
                'media_id' => (string) ($rawMessage['voice']['media_id'] ?? ''),
                'duration' => (int) ($rawMessage['voice']['duration'] ?? 0),
            ]),
            'file' => array_merge($base, [
                'type' => 'file',
                'media_id' => (string) ($rawMessage['file']['media_id'] ?? ''),
                'file_name' => (string) ($rawMessage['file']['file_name'] ?? ''),
            ]),
            'link' => array_merge($base, [
                'type' => 'link',
                'title' => (string) ($rawMessage['link']['title'] ?? ''),
                'text' => (string) ($rawMessage['link']['text'] ?? ''),
                'pic_url' => (string) ($rawMessage['link']['picUrl'] ?? ''),
                'message_url' => (string) ($rawMessage['link']['messageUrl'] ?? ''),
            ]),
            'markdown' => array_merge($base, [
                'type' => 'markdown',
                'title' => (string) ($rawMessage['markdown']['title'] ?? ''),
                'text' => (string) ($rawMessage['markdown']['text'] ?? ''),
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

        $msgType = $message['msgtype'] ?? 'text';
        $payload = [
            'chatid' => $conversationId,
            'msgtype' => $msgType,
            $msgType => $message[$msgType] ?? $message,
        ];

        $response = Http::asJson()->post(
            "https://oapi.dingtalk.com/chat/send?access_token={$accessToken}",
            $payload,
        );

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('DingTalkProvider: send message failed', [
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
        $tenantId = TenantContext::getId();
        $accessToken = $this->getAccessToken($tenantId);

        if ($accessToken === '') {
            return [];
        }

        $response = Http::asJson()->get(
            "https://oapi.dingtalk.com/chat/get?access_token={$accessToken}",
            ['chatid' => $conversationId],
        );

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('DingTalkProvider: get participants failed', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'response' => $result,
            ]);

            return [];
        }

        $members = $result['chat_info']['useridlist'] ?? [];

        return array_map(fn (string $userId) => [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ], $members);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConversationInfo(string $conversationId): array
    {
        $tenantId = TenantContext::getId();
        $accessToken = $this->getAccessToken($tenantId);

        $emptyInfo = [
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
            'channel' => 'dingtalk',
            'name' => '',
            'owner' => '',
            'member_count' => 0,
        ];

        if ($accessToken === '') {
            return $emptyInfo;
        }

        $response = Http::asJson()->get(
            "https://oapi.dingtalk.com/chat/get?access_token={$accessToken}",
            ['chatid' => $conversationId],
        );

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('DingTalkProvider: get conversation info failed', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'response' => $result,
            ]);

            return $emptyInfo;
        }

        $info = $result['chat_info'] ?? [];

        return [
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
            'channel' => 'dingtalk',
            'name' => (string) ($info['name'] ?? ''),
            'owner' => (string) ($info['owner'] ?? ''),
            'member_count' => (int) ($info['useridlist'] ? count($info['useridlist']) : 0),
        ];
    }

    public function getAccessToken(?string $tenantId = null): string
    {
        $cacheKey = "dingtalk:access_token:{$this->appKey}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::get('https://oapi.dingtalk.com/gettoken', [
            'appkey' => $this->appKey,
            'appsecret' => $this->appSecret,
        ]);

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('DingTalkProvider: get access token failed', [
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
}
