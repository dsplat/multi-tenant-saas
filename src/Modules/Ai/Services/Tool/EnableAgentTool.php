<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * enable_agent — 在对话中为租户启用数字员工
 *
 * 小助手发现用户需要某个尚未启用的数字员工时调用本工具完成启用。
 * 本工具注册为 L2：系统自动弹确认卡片，用户确认后才真正执行，
 * 无需（也禁止）再叠加 ask_user_choice 征询。
 *
 * 逻辑：
 * 1. 按 role 查找租户已有 agent → 已启用则直接返回
 * 2. 已有但停用 → 重新启用
 * 3. 不存在 → 从 AgentTemplateRegistry（框架模板 + 下游扩展模板）克隆创建（enabled=true）
 */
class EnableAgentTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $role = trim((string) ($arguments['role'] ?? ''));

        if ($role === '') {
            return ['error' => true, 'message' => 'role 不能为空，请先用 list_agents 查看可用员工角色'];
        }

        // 禁止操作小助手自身
        if ($role === 'system_secretary') {
            return ['error' => true, 'message' => '系统小助手无需手动启用'];
        }

        // 1. 查找租户已有 agent
        $agent = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('role', $role)
            ->first();

        if ($agent && $agent->enabled) {
            return [
                'action' => 'already_enabled',
                'agent_id' => (string) $agent->agent_id,
                'name' => $agent->name,
                'role' => $agent->role,
                'message' => "「{$agent->name}」已处于启用状态，可直接转派。",
            ];
        }

        if ($agent && ! $agent->enabled) {
            $agent->update(['enabled' => true]);

            return [
                'action' => 're_enabled',
                'agent_id' => (string) $agent->agent_id,
                'name' => $agent->name,
                'role' => $agent->role,
                'message' => "已重新启用「{$agent->name}」，现在可以转派任务给她了。",
            ];
        }

        // 2. 从模板克隆创建（框架模板 + 下游注册模板）
        $template = AgentTemplateRegistry::findByKey($role);

        if ($template === null) {
            return [
                'error' => true,
                'message' => "未找到角色为「{$role}」的预置模板，请确认角色标识是否正确。",
            ];
        }

        $newAgent = Agent::create([
            'tenant_id' => $tenantId,
            'name' => $template['name'],
            'role' => $template['role'],
            'avatar' => $template['avatar'] ?? null,
            'system_prompt' => $template['system_prompt'],
            'description' => $template['description'] ?? null,
            'tools' => $template['tools'] ?? [],
            'kb_ids' => $template['kb_ids'] ?? [],
            'feature_keys' => $template['feature_keys'] ?? [],
            'model_config' => $template['model_config'] ?? [],
            'enabled' => true,
            'is_builtin' => true,
            'metadata' => ['enabled_via' => 'assistant_chat'],
        ]);

        return [
            'action' => 'created',
            'agent_id' => (string) $newAgent->agent_id,
            'name' => $newAgent->name,
            'role' => $newAgent->role,
            'message' => "已为你启用「{$newAgent->name}」，现在可以把相关任务转派给她了。",
        ];
    }
}
