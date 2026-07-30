<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use MultiTenantSaas\DTOs\InboundMessage;
use MultiTenantSaas\Modules\Conversation\Models\Message;

/**
 * 渠道无关入站消息管道
 *
 * 串联 ConversationRouter（会话解析）与 EventBusBridge（入库+事件）：
 *   InboundMessage -> 会话解析 -> 入库 -> MessageReceived
 * 由 ChannelWebhookController 在验签/解析后调用。
 */
class MessageRouter
{
    public function __construct(
        protected ConversationRouter $conversationRouter,
        protected EventBusBridge $eventBusBridge,
    ) {}

    /**
     * 处理一条入向消息：解析会话、入库、触发事件，返回落库消息。
     */
    public function handleInbound(int $tenantId, InboundMessage $msg): Message
    {
        $conversation = $this->conversationRouter->resolve($tenantId, $msg);

        return $this->eventBusBridge->dispatch($msg, $conversation);
    }
}
