<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Agent 创建事件
 */
class AgentCreated
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $agentId
    ) {}
}
