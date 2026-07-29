<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;

/**
 * 数字员工名录生成器（机器文档）
 *
 * 汇总框架 BuiltinAgentTemplates 与下游扩展模板（经
 * config('ai.secretary.extra_template_classes') 注册，如 ScrmAgentTemplates），
 * 输出每位数字员工的职责/能力/工具清单——小秘书转派路由的事实来源。
 */
class AgentDirectoryGenerator
{
    /**
     * 生成数字员工名录 markdown（含 frontmatter）
     */
    public function generate(): string
    {
        $lines = [
            '---',
            'title: 数字员工名录',
            'module: ',
            'audience: operator',
            'locale: zh',
            '---',
            '',
            '# 数字员工名录',
            '',
            '> 本文档由 `secretary:kb:generate` 自动生成，请勿手工编辑。',
            '> 小秘书依据本名录识别业务诉求并转派给对应数字员工。',
            '',
        ];

        foreach ($this->allTemplates() as $template) {
            // 小秘书本人不进名录（她是转派方）
            if (($template['template_key'] ?? '') === 'system_secretary') {
                continue;
            }

            $lines[] = sprintf('## %s（%s）', $template['name'] ?? '', $template['template_key'] ?? '');
            $lines[] = '';
            $lines[] = '- 角色：'.($template['role'] ?? '');
            $lines[] = '- 职责：'.($template['description'] ?? '');

            if (! empty($template['tools'])) {
                $lines[] = '- 工具：'.implode('、', (array) $template['tools']);
            }

            if (! empty($template['feature_keys'])) {
                $lines[] = '- 能力：'.implode('、', (array) $template['feature_keys']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * 框架模板 + 下游扩展模板（经 AgentTemplateRegistry 归一）
     *
     * @return list<array<string, mixed>>
     */
    private function allTemplates(): array
    {
        return AgentTemplateRegistry::definitions();
    }
}
