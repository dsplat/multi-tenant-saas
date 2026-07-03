<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Contracts\IdGeneratorContract;

class SlackProvider implements ChannelContract
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    public function onMessage(array $rawMessage): array
    {
        $tenantId = TenantContext::getId();

        $event = $rawMessage['event'] ?? [];
        $type = $rawMessage['type'] ?? '';

        if ($type === 'url_verification') {
            return [
                'challenge' => $rawMessage['challenge'] ?? '',
            ];
        }

        if ($type === 'event_callback' && !empty($event)) {
            return [
                'conversation_id' => $event['channel'] ?? '',
                'message_id' => $event['ts'] ?? '',
                'user_id' => $event['user'] ?? '',
                'text' => $event['text'] ?? '',
                'team_id' => $rawMessage['team_id'] ?? '',
                'tenant_id' => $tenantId,
                'event_type' => $event['type'] ?? '',
                'raw' => $event,
            ];
        }

        return [
            'conversation_id' => $rawMessage['channel_id'] ?? $rawMessage['channel'] ?? '',
            'message_id' => $rawMessage['ts'] ?? (string) $this->idGenerator->generate(),
            'user_id' => $rawMessage['user'] ?? '',
            'text' => $rawMessage['text'] ?? '',
            'team_id' => $rawMessage['team_id'] ?? '',
            'tenant_id' => $tenantId,
            'event_type' => $type,
            'raw' => $rawMessage,
        ];
    }

    public function sendMessage(string $conversationId, array $message): bool
    {
        $tenantId = TenantContext::getId();
        $token = $this->resolveBotToken($tenantId);

        if (empty($token)) {
            Log::warning('SlackProvider: bot token not configured', [
                'tenant_id' => $tenantId,
            ]);

            return false;
        }

        $payload = [
            'channel' => $conversationId,
            'text' => $message['text'] ?? '',
            'blocks' => $message['blocks'] ?? [],
            'thread_ts' => $message['thread_ts'] ?? null,
        ];

        $response = Http::withToken($token)
            ->asJson()
            ->post('https://slack.com/api/chat.postMessage', $payload);

        if (!$response->successful() || !($response->json('ok') ?? false)) {
            Log::error('SlackProvider: failed to send message', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'error' => $response->json('error') ?? 'unknown',
            ]);

            return false;
        }

        return true;
    }

    public function getParticipants(string $conversationId): array
    {
        $tenantId = TenantContext::getId();
        $token = $this->resolveBotToken($tenantId);

        if (empty($token)) {
            Log::warning('SlackProvider: bot token not configured', [
                'tenant_id' => $tenantId,
            ]);

            return [];
        }

        $response = Http::withToken($token)
            ->get('https://slack.com/api/conversations.members', [
                'channel' => $conversationId,
            ]);

        if (!$response->successful() || !($response->json('ok') ?? false)) {
            Log::error('SlackProvider: failed to get participants', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'error' => $response->json('error') ?? 'unknown',
            ]);

            return [];
        }

        $members = $response->json('members') ?? [];

        return array_map(function (string $memberId) use ($tenantId): array {
            return [
                'user_id' => $memberId,
                'tenant_id' => $tenantId,
            ];
        }, $members);
    }

    public function getConversationInfo(string $conversationId): array
    {
        $tenantId = TenantContext::getId();
        $token = $this->resolveBotToken($tenantId);

        if (empty($token)) {
            Log::warning('SlackProvider: bot token not configured', [
                'tenant_id' => $tenantId,
            ]);

            return [
                'conversation_id' => $conversationId,
                'tenant_id' => $tenantId,
                'name' => '',
                'is_channel' => false,
                'is_im' => false,
                'topic' => '',
                'purpose' => '',
                'num_members' => 0,
            ];
        }

        $response = Http::withToken($token)
            ->get('https://slack.com/api/conversations.info', [
                'channel' => $conversationId,
            ]);

        if (!$response->successful() || !($response->json('ok') ?? false)) {
            Log::error('SlackProvider: failed to get conversation info', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'error' => $response->json('error') ?? 'unknown',
            ]);

            return [
                'conversation_id' => $conversationId,
                'tenant_id' => $tenantId,
                'name' => '',
                'is_channel' => false,
                'is_im' => false,
                'topic' => '',
                'purpose' => '',
                'num_members' => 0,
            ];
        }

        $channel = $response->json('channel') ?? [];

        return [
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
            'name' => $channel['name'] ?? '',
            'is_channel' => $channel['is_channel'] ?? false,
            'is_im' => $channel['is_im'] ?? false,
            'topic' => $channel['topic']['value'] ?? '',
            'purpose' => $channel['purpose']['value'] ?? '',
            'num_members' => $channel['num_members'] ?? 0,
        ];
    }

    protected function resolveBotToken(?string $tenantId): string
    {
        return config('services.slack.bot_token', '');
    }
}