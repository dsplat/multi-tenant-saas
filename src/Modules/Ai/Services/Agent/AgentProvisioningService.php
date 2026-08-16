<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;

/**
 * 数字员工懒开通服务
 *
 * 开通节奏（不在审批/入驻时预装，避免一次性铺开造成负担）：
 * - 系统小秘书：用户首次打开小助手对话时自动开通（ensureSecretary，
 *   无需询问确认——这是系统最基本的能力）；
 * - 其余数字员工：秘书在执行中判断需要时，先告知门槛/成本征得
 *   用户确认，再经 enable_agent 工具克隆启用（见 EnableAgentTool）。
 *
 * secretary:install / agents:enable 命令保留作运维批量补装工具。
 */
class AgentProvisioningService
{
    public function __construct(
        private readonly AgentService $agentService,
    ) {}

    /**
     * 确保租户拥有已启用的系统小秘书（幂等 + 并发安全）
     *
     * 已存在（无论启用与否）直接返回并确保启用；不存在则从模板克隆。
     * 首次打开的并发请求经缓存锁 + 锁内复查收敛为一次克隆。
     */
    public function ensureSecretary(int $tenantId): ?Agent
    {
        $existing = $this->findSecretary($tenantId);

        if ($existing !== null) {
            if (! $existing->enabled) {
                $existing->update(['enabled' => true]);
            }

            return $existing;
        }

        $lock = Cache::lock("provision-secretary:{$tenantId}", 15);

        try {
            $lock->block(10);

            // 锁内复查：并发请求可能已完成克隆
            $existing = $this->findSecretary($tenantId);

            if ($existing !== null) {
                return $existing;
            }

            $template = AgentTemplateRegistry::findByKey('system_secretary');

            if ($template === null) {
                return null;
            }

            return $this->agentService->cloneFromTemplate((int) $template['template_id'], $tenantId);
        } catch (\Throwable) {
            // 锁不可用（缓存驱动不支持等）时退化为直接克隆，
            // 最坏情况产生重复记录时以最早一条为准（findSecretary orderBy agent_id）
            $existing = $this->findSecretary($tenantId);

            return $existing ?? $this->cloneWithoutLock($tenantId);
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
    }

    private function findSecretary(int $tenantId): ?Agent
    {
        $original = TenantContext::getId();

        try {
            // Agent 受租户作用域（fail-closed）约束，显式建立上下文
            TenantContext::setTenantId((string) $tenantId);

            return Agent::query()
                ->where('role', 'system_secretary')
                ->orderBy('agent_id')
                ->first();
        } finally {
            $original !== null && $original !== ''
                ? TenantContext::setTenantId($original)
                : TenantContext::clear();
        }
    }

    private function cloneWithoutLock(int $tenantId): ?Agent
    {
        $template = AgentTemplateRegistry::findByKey('system_secretary');

        if ($template === null) {
            return null;
        }

        return rescue(
            fn () => $this->agentService->cloneFromTemplate((int) $template['template_id'], $tenantId),
            fn () => $this->findSecretary($tenantId),
        );
    }
}
