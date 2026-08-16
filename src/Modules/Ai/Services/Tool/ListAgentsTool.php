<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * list_agents — 列出本租户可用的数字员工
 *
 * 秘书调度的前置工具：先看清有哪些员工、各自擅长什么，
 * 再决定是否 delegate_to_agent 转派。
 *
 * 懒开通策略下同时返回「尚未开通员工名录」（available_to_enable），
 * 供秘书向用户介绍能力/成本并征得确认后调 enable_agent 启用。
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

        $enabledRoles = $agents->pluck('role')->all();

        // 未开通名录：模板注册表（框架 + 下游扩展）中尚未克隆启用的员工，
        // 附所用模型供秘书向用户说明成本档位
        $availableToEnable = [];

        foreach (AgentTemplateRegistry::enableable() as $template) {
            $role = (string) ($template['role'] ?? '');

            if ($role === '' || in_array($role, $enabledRoles, true)) {
                continue;
            }

            // 已克隆但被停用的不算「待开通」（重新启用走 enable_agent 的 re_enabled 分支）
            $disabled = Agent::query()
                ->where('tenant_id', $tenantId)
                ->where('role', $role)
                ->exists();

            if ($disabled) {
                continue;
            }

            $modelConfig = (array) ($template['model_config'] ?? []);

            $availableToEnable[] = [
                'role' => $role,
                'name' => (string) ($template['name'] ?? $role),
                'description' => (string) ($template['description'] ?? ''),
                'model' => (string) ($modelConfig['preferred_model'] ?? ''),
            ];
        }

        return [
            'total' => $agents->count(),
            'agents' => $agents->map(fn (Agent $agent) => [
                'agent_id' => (string) $agent->agent_id,
                'name' => $agent->name,
                'role' => $agent->role,
                'description' => (string) $agent->description,
            ])->all(),
            'available_to_enable' => $availableToEnable,
            'hint' => '需要但不在 agents 列表中的员工，先查 available_to_enable，向用户说明能力与所用模型（成本档位）并征得同意后调 enable_agent 启用，再转派。',
        ];
    }
}
