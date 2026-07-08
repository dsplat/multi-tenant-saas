<?php

namespace MultiTenantSaas\DTOs;

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