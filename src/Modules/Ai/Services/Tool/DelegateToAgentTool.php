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
 *
 * agent_id 容错：跨轮纯文本历史会丢失 list_agents 结果里的长数字
 * agent_id，模型常改传 role/name 或编造短 ID。先按 agent_id 直查，
 * 未命中再按 role/name 容错匹配；仍未命中返回已启用员工清单引导自愈。
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

        // 机械兜底：模型传了 role（如 marketing）或员工名而非长数字 agent_id
        if (! $agent) {
            $agent = Agent::query()
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($agentId) {
                    $q->where('role', $agentId)->orWhere('name', $agentId);
                })
                ->first();
        }

        if (! $agent) {
            return $this->agentNotFoundError($agentId, $tenantId);
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

    /**
     * 目标不存在：附当前租户已启用员工清单（含真实 agent_id），
     * 引导模型用真实编号重试一次自愈，而不是原样反复重试。
     */
    private function agentNotFoundError(string $agentId, int $tenantId): array
    {
        $enabled = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where('role', '!=', 'system_secretary')
            ->orderBy('agent_id')
            ->get(['agent_id', 'name', 'role'])
            ->map(fn (Agent $a) => [
                'agent_id' => (string) $a->agent_id,
                'name' => $a->name,
                'role' => $a->role,
            ])->values()->toArray();

        return [
            'error' => true,
            'message' => "数字员工 [{$agentId}] 不存在，请勿编造 agent_id。请用 enabled_agents 中的真实 agent_id（长数字）或 role 重试一次；名单之外的员工需先经用户同意用 enable_agent 开通。",
            'enabled_agents' => $enabled,
        ];
    }
}
