<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\DTOs\InboundMessage;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\Infrastructure\Services\IdGenerator;

/**
 * 存储 + 事件发布（渠道无关）
 *
 * 将归一化入向消息落 messages 表，更新会话计数，再触发 MessageReceived 事件。
 * 外部发送者非系统 User：sender_id=null、sender_type=external、平台身份存 metadata.external_from。
 */
class EventBusBridge
{
    public function __construct(
        protected IdGenerator $idGenerator,
    ) {}

    /**
     * 入库并触发 MessageReceived。
     */
    public function dispatch(InboundMessage $msg, Conversation $conversation): Message
    {
        try {
            $message = Message::create([
                'message_id' => $this->idGenerator->generate(),
                'conversation_id' => $conversation->conversation_id,
                'tenant_id' => $conversation->tenant_id,
                'sender_id' => null,
                'sender_type' => $msg->senderType,
                'content' => $msg->content,
                'type' => $msg->msgType,
                'metadata' => [
                    'channel' => $msg->channel,
                    'external_from' => $msg->senderExternalId,
                    'platform_msg_id' => $msg->platformMsgId,
                ],
            ]);

            $conversation->forceFill([
                'last_message_at' => now(),
                'message_count' => (int) $conversation->message_count + 1,
            ])->save();

            Event::dispatch(new MessageReceived($message, $msg->channel));

            return $message;
        } catch (\Throwable $e) {
            Log::error('EventBusBridge: dispatch failed', [
                'channel' => $msg->channel,
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
