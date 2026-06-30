<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 会话开始事件
 */
class ConversationStarted
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $agentId,
        public readonly int $conversationId
    ) {}
}
