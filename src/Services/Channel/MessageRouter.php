<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\DTOs\MessageDTO;
use MultiTenantSaas\Services\IdGenerator;

class MessageRouter
{
    protected array $channels = [];

    public function __construct(
        protected IdGenerator $idGenerator,
        protected EventBusBridge $eventBusBridge,
    ) {}

    /**
     * 注册渠道处理器.
     */
    public function registerChannel(string $type, ChannelContract $provider): void
    {
        $this->channels[$type] = $provider;
    }

    /**
     * 路由原始消息，通过渠道处理器转换为 MessageDTO.
     */
    public function route(string $channelType, array $rawMessage): MessageDTO
    {
        $provider = $this->channels[$channelType] ?? null;

        if (!$provider instanceof ChannelContract) {
            throw new \InvalidArgumentException("Channel provider not found: {$channelType}");
        }

        $messageData = $provider->onMessage($rawMessage);

        if (empty($messageData['message_id'])) {
            $messageData['message_id'] = (string) $this->idGenerator->generate();
        }

        return MessageDTO::fromArray($messageData);
    }

    /**
     * 分发消息到事件总线.
     */
    public function dispatch(MessageDTO $message): void
    {
        $this->eventBusBridge->dispatch($message);
    }

    /**
     * 获取已注册的渠道类型列表.
     */
    public function getRegisteredChannels(): array
    {
        return array_keys($this->channels);
    }

    /**
     * 检查渠道是否已注册.
     */
    public function hasChannel(string $type): bool
    {
        return isset($this->channels[$type]);
    }
}
