<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentService;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * agents:enable — 为租户批量启用数字员工
 *
 * 从 AgentTemplateRegistry（框架模板 + 下游扩展模板，如 SCRM 8 员工）
 * 批量克隆启用。幂等：已存在则跳过（停用的重新启用）；
 * --sync-model 时已存在员工同步模板最新 model_config。
 * 小秘书不在本命令范围（由 secretary:install 单独安装）。
 */
class AgentsEnableCommand extends Command
{
    protected $signature = 'agents:enable
        {--tenant= : 仅处理指定 tenant_id，缺省为全部租户}
        {--all : 启用全部可用模板}
        {--role=* : 仅启用指定角色（可多次传入）}
        {--sync-model : 已存在的员工同步模板最新 model_config}';

    protected $description = '为租户批量启用数字员工（幂等；含下游注册的扩展模板）';

    public function handle(AgentService $agentService): int
    {
        $roles = array_filter((array) $this->option('role'));

        if (! $this->option('all') && $roles === []) {
            $this->error('请指定 --all 或至少一个 --role=<角色标识>。');

            return self::FAILURE;
        }

        // 待启用模板集合（小秘书除外）
        $templates = AgentTemplateRegistry::enableable();

        if ($roles !== []) {
            $templates = array_values(array_filter(
                $templates,
                static fn (array $t): bool => in_array($t['role'], $roles, true),
            ));

            $missing = array_diff($roles, array_column($templates, 'role'));
            if ($missing !== []) {
                $this->error('未找到角色模板：' . implode(', ', $missing) . '（用 list_agents 或模板注册表核对角色标识）');

                return self::FAILURE;
            }
        }

        if ($templates === []) {
            $this->warn('没有可启用的模板。');

            return self::SUCCESS;
        }

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

        $syncModel = (bool) $this->option('sync-model');
        $created = 0;
        $reEnabled = 0;
        $synced = 0;
        $skipped = 0;
        $originalTenantId = TenantContext::getId();

        try {
            foreach ($tenantIds as $tenantId) {
                // Agent 受租户作用域（fail-closed）约束，逐租户建立上下文
                TenantContext::setTenantId((string) $tenantId);

                foreach ($templates as $template) {
                    $existing = Agent::query()
                        ->where('role', $template['role'])
                        ->first();

                    if ($existing !== null) {
                        $changed = false;

                        if (! $existing->enabled) {
                            $existing->enabled = true;
                            $changed = true;
                            $reEnabled++;
                            $this->line("租户 {$tenantId} 重新启用 {$template['role']}（agent_id={$existing->agent_id}）");
                        }

                        if ($syncModel && $template['model_config'] !== []) {
                            $existing->model_config = $template['model_config'];
                            $changed = true;
                            $synced++;
                            $this->line("租户 {$tenantId} 同步模型配置 {$template['role']}");
                        }

                        $changed ? $existing->save() : $skipped++;

                        continue;
                    }

                    $agent = $agentService->cloneFromTemplate((int) $template['template_id'], (int) $tenantId);
                    $this->line("租户 {$tenantId} 启用 {$template['role']}（agent_id={$agent->agent_id}）");
                    $created++;
                }
            }
        } finally {
            $originalTenantId !== null && $originalTenantId !== ''
                ? TenantContext::setTenantId($originalTenantId)
                : TenantContext::clear();
        }

        $this->info("新建 {$created} 个，重新启用 {$reEnabled} 个，同步模型 {$synced} 个，跳过 {$skipped} 个（已存在）。");

        return self::SUCCESS;
    }
}
