<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Agent 禁用事件
 */
class AgentDisabled
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $agentId
    ) {}
}
