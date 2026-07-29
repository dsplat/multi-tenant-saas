<?php

namespace MultiTenantSaas\Modules\Ibot\DTOs;

/**
 * 归一化入向消息 — 各频道 parseInbound() 的统一产物
 */
class IbotInboundMessage
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $text,
        public readonly ?string $messageId = null,
        public readonly array $raw = [],
    ) {}
}
