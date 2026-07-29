<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;

/**
 * 工具目录生成器（机器文档）
 *
 * 遍历 ToolRegistry 运行时注册的所有工具，按分类分组输出 markdown 目录。
 * 让 AI 小助手通过 system_kb_search 即可获知"我能做什么"。
 *
 * 由 secretary:kb:index 在部署时自动生成，禁止手工编辑。
 */
class ToolCatalogGenerator
{
    public function __construct(
        private ToolRegistry $registry,
    ) {}

    /**
     * 生成工具目录 markdown（含 frontmatter）
     */
    public function generate(): string
    {
        $tools = $this->registry->all();

        // 按 category 分组
        $grouped = [];
        foreach ($tools as $tool) {
            $grouped[$tool->category][] = $tool;
        }
        ksort($grouped);

        $lines = [
            '---',
            'title: AI 工具目录',
            'module: ',
            'audience: internal',
            'locale: zh',
            'generated_by: secretary:kb:index',
            'generated_at: '.date('c'),
            '---',
            '',
            '# AI 工具目录',
            '',
            '> 本文档由 `secretary:kb:index` 自动生成，请勿手工编辑。',
            '> 列出当前系统所有已注册的 AI 工具（框架层 + 项目层）。',
            '',
            '## 风险等级说明',
            '',
            '- **L1**（读/低风险）：直接执行，无需用户确认',
            '- **L2**（写操作）：需用户确认后才执行',
            '',
        ];

        $categoryLabels = [
            'secretary' => '小秘书核心',
            'core' => '核心能力',
            'ai' => 'AI 推理',
            'kb' => '知识库',
            'channel' => '渠道',
            'storage' => '存储',
            'workflow' => '工作流',
            'customer' => '客户管理',
            'analysis' => '数据分析',
            'community' => '社群运营',
            'content' => '内容管理',
            'message' => '消息触达',
            'event' => '活动管理',
            'report' => '报表',
            'knowledge' => '知识库',
        ];

        foreach ($grouped as $category => $categoryTools) {
            $label = $categoryLabels[$category] ?? ucfirst($category);
            $lines[] = "## {$label}（{$category}）";
            $lines[] = '';
            $lines[] = '| 工具 | 名称 | 风险 | 说明 |';
            $lines[] = '|------|------|------|------|';

            foreach ($categoryTools as $tool) {
                $desc = mb_strlen($tool->description) > 60
                    ? mb_substr($tool->description, 0, 57).'...'
                    : $tool->description;
                $lines[] = sprintf('| `%s` | %s | %s | %s |', $tool->slug, $tool->name, $tool->risk, $desc);
            }

            $lines[] = '';
        }

        // 统计摘要
        $total = $tools->count();
        $l2 = $tools->filter(fn ($t) => $t->risk === 'L2')->count();
        $lines[] = '---';
        $lines[] = '';
        $lines[] = sprintf('共 **%d** 个工具（L1: %d，L2: %d），%d 个分类。', $total, $total - $l2, $l2, count($grouped));
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }
}
