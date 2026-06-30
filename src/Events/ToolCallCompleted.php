<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 工具调用完成事件
 */
class ToolCallCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $agentId,
        public readonly int $conversationId,
        public readonly string $toolName
    ) {}
}
