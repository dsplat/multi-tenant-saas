<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * list_agents — 列出本租户可用的数字员工
 *
 * 秘书调度的前置工具：先看清有哪些员工、各自擅长什么，
 * 再决定是否 delegate_to_agent 转派。
 */
class ListAgentsTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $agents = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where('role', '!=', 'system_secretary')
            ->orderBy('agent_id')
            ->get(['agent_id', 'name', 'role', 'description']);

        return [
            'total' => $agents->count(),
            'agents' => $agents->map(fn (Agent $agent) => [
                'agent_id' => (string) $agent->agent_id,
                'name' => $agent->name,
                'role' => $agent->role,
                'description' => (string) $agent->description,
            ])->all(),
        ];
    }
}
