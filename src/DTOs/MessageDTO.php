<?php

declare(strict_types=1);

namespace MultiTenantSaas\DTOs;

use JsonSerializable;
use MultiTenantSaas\Models\Message;

class MessageDTO implements JsonSerializable
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $conversationId,
        public readonly string $senderId,
        public readonly string $senderType,
        public readonly string $content,
        public readonly string $type = 'text',
        public readonly ?string $replyToId = null,
        public readonly array $metadata = [],
        public readonly ?int $tenantId = null,
        public readonly string $channel = 'web',
        public readonly ?string $timestamp = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            messageId: (string) ($data['message_id'] ?? ''),
            conversationId: (string) ($data['conversation_id'] ?? ''),
            senderId: (string) ($data['sender_id'] ?? ''),
            senderType: (string) ($data['sender_type'] ?? 'user'),
            content: (string) ($data['content'] ?? ''),
            type: (string) ($data['type'] ?? 'text'),
            replyToId: isset($data['reply_to_id']) ? (string) $data['reply_to_id'] : null,
            metadata: (array) ($data['metadata'] ?? []),
            tenantId: isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            channel: (string) ($data['channel'] ?? 'web'),
            timestamp: isset($data['timestamp']) ? (string) $data['timestamp'] : null,
        );
    }

    public static function fromModel(Message $message, string $channel = 'web'): self
    {
        return new self(
            messageId: (string) $message->message_id,
            conversationId: (string) $message->conversation_id,
            senderId: (string) $message->sender_id,
            senderType: (string) $message->sender_type,
            content: (string) $message->content,
            type: (string) ($message->type ?? 'text'),
            replyToId: $message->reply_to_id ? (string) $message->reply_to_id : null,
            metadata: (array) ($message->metadata ?? []),
            tenantId: $message->tenant_id ? (int) $message->tenant_id : null,
            channel: $channel,
            timestamp: $message->created_at?->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'sender_type' => $this->senderType,
            'content' => $this->content,
            'type' => $this->type,
            'reply_to_id' => $this->replyToId,
            'metadata' => $this->metadata,
            'tenant_id' => $this->tenantId,
            'channel' => $this->channel,
            'timestamp' => $this->timestamp,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}