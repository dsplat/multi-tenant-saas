<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentService;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * secretary:install — 为租户安装系统小秘书（第 0 号数字员工，seq=0）
 *
 * 从 BuiltinAgentTemplates 的 system_secretary 模板克隆到指定租户（或全部租户）。
 * 幂等：租户已存在 role=system_secretary 的员工则跳过。
 */
class SecretaryInstallCommand extends Command
{
    protected $signature = 'secretary:install {--tenant= : 仅安装到指定 tenant_id，缺省为全部租户}';

    protected $description = '为租户安装系统小秘书数字员工（幂等，已安装则跳过）';

    public function handle(AgentService $agentService): int
    {
        $tenantOption = $this->option('tenant');

        $query = Tenant::query();

        if ($tenantOption !== null && $tenantOption !== '') {
            $query->where('tenant_id', $tenantOption);
        }

        $tenantIds = $query->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            $this->warn('没有匹配的租户。');

            return self::SUCCESS;
        }

        // 按 template_key 定位模板（template_id 不硬编码）
        $secretaryTemplateId = (int) BuiltinAgentTemplates::findByKey('system_secretary')['template_id'];

        $installed = 0;
        $skipped = 0;
        $originalTenantId = TenantContext::getId();

        try {
            foreach ($tenantIds as $tenantId) {
                // Agent 受租户作用域（fail-closed）约束，逐租户建立上下文
                TenantContext::setTenantId((string) $tenantId);

                $exists = Agent::query()
                    ->where('role', 'system_secretary')
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $agent = $agentService->cloneFromTemplate($secretaryTemplateId, (int) $tenantId);
                $this->line("租户 {$tenantId} 安装完成（agent_id={$agent->agent_id}）");
                $installed++;
            }
        } finally {
            $originalTenantId !== null && $originalTenantId !== ''
                ? TenantContext::setTenantId($originalTenantId)
                : TenantContext::clear();
        }

        $this->info("安装 {$installed} 个，跳过 {$skipped} 个（已存在）。");

        return self::SUCCESS;
    }
}
