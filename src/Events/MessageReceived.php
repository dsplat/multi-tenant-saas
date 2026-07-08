<?php

declare(strict_types=1);

namespace MultiTenantSaas\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MultiTenantSaas\DTOs\MessageDTO;
use MultiTenantSaas\Models\Message;

class MessageReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message $message,
        public readonly string $channel = 'web',
    ) {}

    public function toMessageDTO(): MessageDTO
    {
        return MessageDTO::fromModel($this->message, $this->channel);
    }
}
