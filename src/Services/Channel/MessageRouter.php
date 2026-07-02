<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Services\Conversation\ConversationService;
use MultiTenantSaas\Services\Conversation\MessageService;

class MessageRouter
{
    public function __construct(
        protected ChannelManager $channelManager,
        protected ConversationService $conversationService,
    ) {}

    /**
     * 路由来自渠道的消息到会话系统.
     */
    public function routeMessage(string $channelName, array $rawMessage): void
    {
        $channel = $this->channelManager->get($channelName);

        if (! $channel instanceof ChannelContract) {
            Log::warning('MessageRouter: channel not found', ['channel' => $channelName]);

            return;
        }

        try {
            $messageData = $channel->onMessage($rawMessage);

            if (empty($messageData)) {
                return;
            }

            // 消息数据已由 channel 的 onMessage 处理完成
            // 具体的会话/消息持久化由 channel 自行决定
        } catch (\Throwable $e) {
            Log::error('MessageRouter: failed to route message', [
                'channel' => $channelName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
