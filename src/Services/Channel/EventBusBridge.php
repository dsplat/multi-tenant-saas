<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\DTOs\MessageDTO;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Conversation\Models\Message;

class EventBusBridge
{
    /**
     * 将 MessageDTO 转换为 Message 模型并触发 MessageReceived 事件.
     */
    public function dispatch(MessageDTO $dto): void
    {
        try {
            $message = Message::create([
                'message_id' => $dto->messageId,
                'conversation_id' => $dto->conversationId,
                'sender_id' => $dto->senderId,
                'sender_type' => $dto->senderType,
                'content' => $dto->content,
                'type' => $dto->type,
                'reply_to_id' => $dto->replyToId,
                'metadata' => $dto->metadata,
                'tenant_id' => TenantContext::getId(),
            ]);

            Event::dispatch(new MessageReceived($message, $dto->metadata['channel'] ?? 'web'));
        } catch (\Throwable $e) {
            Log::error('EventBusBridge: dispatch failed', [
                'message_id' => $dto->messageId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
