<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * delegate_to_agent — 返回转派指令（只读结构化输出）
 *
 * 秘书调度闭环：校验目标员工存在且启用后，返回 {action: delegate}
 * 结构化结果，由前端 AiAssistant 捕获后切换会话到目标员工，
 * 并把 handoff_message 作为开场消息带入。后端不做会话迁移。
 */
class DelegateToAgentTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $agentId = trim((string) ($arguments['agent_id'] ?? ''));

        if ($agentId === '') {
            return ['error' => true, 'message' => 'agent_id 不能为空'];
        }

        $agent = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->first();

        if (! $agent) {
            return ['error' => true, 'message' => "数字员工 [{$agentId}] 不存在，请先用 list_agents 查询可用员工"];
        }

        if (! $agent->enabled) {
            return ['error' => true, 'message' => "数字员工 [{$agent->name}] 已停用，无法转派"];
        }

        return [
            'action' => 'delegate',
            'agent_id' => (string) $agent->agent_id,
            'agent_name' => $agent->name,
            'agent_role' => $agent->role,
            'reason' => trim((string) ($arguments['reason'] ?? '')),
            'handoff_message' => trim((string) ($arguments['handoff_message'] ?? '')),
        ];
    }
}
