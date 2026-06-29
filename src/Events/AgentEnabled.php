<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Agent 启用事件
 */
class AgentEnabled
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $agentId
    ) {}
}
