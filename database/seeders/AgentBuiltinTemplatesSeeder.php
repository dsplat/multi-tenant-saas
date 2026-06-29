<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use MultiTenantSaas\Services\Agent\BuiltinAgentTemplates;

/**
 * 预置 Agent 模板 Seeder（可选）
 *
 * 仅用于在控制台查看或导出预置模板列表。
 * 模板本身为内存数据（BuiltinAgentTemplates），不写入 agents 表；
 * 如需将模板实例化为某租户的 Agent，请使用 AgentService::cloneFromTemplate()。
 */
class AgentBuiltinTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        /** @var Collection<int, array<string, mixed>> $templates */
        $templates = BuiltinAgentTemplates::all();

        $this->command->info('预置 Agent 模板列表（共 ' . $templates->count() . ' 个）：');

        $templates->each(function (array $template): void {
            $this->line(sprintf(
                '  [%d] %-18s %s',
                $template['template_id'],
                $template['template_key'],
                $template['name'],
            ));
        });

        $this->command->info('提示：使用 AgentService::cloneFromTemplate() 将模板克隆到租户。');
    }
}
