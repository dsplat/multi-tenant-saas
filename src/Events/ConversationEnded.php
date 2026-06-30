<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 会话结束事件
 */
class ConversationEnded
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $agentId,
        public readonly int $conversationId
    ) {}
}
