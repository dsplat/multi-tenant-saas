<?php

declare(strict_types=1);

namespace MultiTenantSaas\DTOs;

use MultiTenantSaas\Modules\Conversation\Models\Message;

class MessageDTO
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
    ) {}

    public static function fromModel(Message $message, string $channel = 'web'): self
    {
        $metadata = $message->metadata ?? [];
        $metadata['channel'] = $channel;

        return new self(
            messageId: (string) $message->message_id,
            conversationId: (string) $message->conversation_id,
            senderId: (string) $message->sender_id,
            senderType: $message->sender_type ?? 'user',
            content: $message->content ?? '',
            type: $message->type ?? 'text',
            replyToId: $message->reply_to_id ? (string) $message->reply_to_id : null,
            metadata: $metadata,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            messageId: $data['message_id'] ?? '',
            conversationId: $data['conversation_id'] ?? '',
            senderId: $data['sender_id'] ?? '',
            senderType: $data['sender_type'] ?? 'user',
            content: $data['content'] ?? '',
            type: $data['type'] ?? 'text',
            replyToId: $data['reply_to_id'] ?? null,
            metadata: $data['metadata'] ?? [],
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
        ];
    }
}
