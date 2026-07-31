<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;

/**
 * 系统能力图谱生成器（机器文档）——「模块能力 → 工具 → 典型后续动作」
 *
 * AI 小助手推断「接下来该做什么」的知识来源：用户创建/讨论某类业务对象时，
 * AI 经 system_kb_search 检索本图谱，结合各模块自声明的典型后续动作，
 * 主动建议下一步（如活动创建后 → 策划排期 / 制作海报 / 安排群发传播）。
 *
 * 数据来源（零中心化配置）：
 *  - ToolRegistry 运行时全部工具（部署时在下游运行，含项目层注册的工具）
 *  - 各模块 resources/kb/next-actions.md 约定文件（模块自声明，
 *    SystemKbRegistry 零配置发现，下游模块新增后重跑 kb:index 即被收录）
 *
 * 由 secretary:kb:index 在部署时自动生成，禁止手工编辑。
 */
class CapabilityMapGenerator
{
    /** 模块自声明后续动作的约定文件名 */
    private const NEXT_ACTIONS_FILE = 'next-actions.md';

    public function __construct(
        private ToolRegistry $registry,
        private SystemKbRegistry $kbRegistry,
    ) {}

    /**
     * 生成能力图谱 markdown（含 frontmatter）
     */
    public function generate(): string
    {
        $lines = [
            '---',
            'title: 系统能力图谱',
            'module: ',
            'audience: internal',
            'locale: zh',
            'generated_by: secretary:kb:index',
            'generated_at: '.date('c'),
            '---',
            '',
            '# 系统能力图谱（capability map）',
            '',
            '> 本文档由 `secretary:kb:index` 自动生成，请勿手工编辑。',
            '> 描述「模块能力 → 工具 → 典型后续动作」的关系图谱，供 AI 推断下一步操作。',
            '',
            '## 使用指引（给 AI）',
            '',
            '- 用户创建或讨论某类业务对象（活动、客户、计划等）时，先查本图谱：该对象所属模块有哪些工具、模块声明了哪些典型后续动作',
            '- 结合「典型后续动作」主动建议用户下一步（策划 → 排期 → 营销 → 传播等），不要等用户逐项想起',
            '- 建议动作前用 `thread_review` 了解该事项当前进展与遗漏，避免重复建议已完成的事',
            '- L2 工具执行前系统会自动弹出用户确认卡片，可放心发起，无需自行犹豫',
            '',
        ];

        $lines = array_merge($lines, $this->buildToolSection());
        $lines = array_merge($lines, $this->buildNextActionsSection());

        return implode("\n", $lines)."\n";
    }

    /**
     * 模块能力与工具简表（按 category 分组；详情见 tool-catalog.md）
     *
     * @return list<string>
     */
    private function buildToolSection(): array
    {
        $grouped = [];
        foreach ($this->registry->all() as $tool) {
            $grouped[$tool->category][] = $tool;
        }
        ksort($grouped);

        $lines = [
            '## 模块能力与工具',
            '',
            '> 各分类工具速查（参数与完整说明见「AI 工具目录」tool-catalog.md）。',
            '',
        ];

        foreach ($grouped as $category => $categoryTools) {
            $slugs = array_map(
                fn ($tool) => sprintf('`%s`(%s%s)', $tool->slug, $tool->name, $tool->risk === 'L2' ? '·L2需确认' : ''),
                $categoryTools,
            );
            $lines[] = sprintf('- **%s**：%s', $category, implode('、', $slugs));
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * 聚合各模块自声明的典型后续动作段落
     *
     * @return list<string>
     */
    private function buildNextActionsSection(): array
    {
        $lines = [
            '## 典型后续动作（模块自声明）',
            '',
            '> 各模块在 `resources/kb/next-actions.md` 中声明本模块业务对象的典型后续动作链。',
            '',
        ];

        $found = false;

        foreach ($this->kbRegistry->discover() as $doc) {
            if (basename($doc['path']) !== self::NEXT_ACTIONS_FILE) {
                continue;
            }

            $body = $this->stripFrontmatter((string) @file_get_contents($doc['absolute_path']));
            if (trim($body) === '') {
                continue;
            }

            $found = true;
            $module = $doc['module'] !== '' ? $doc['module'] : '(全局)';
            $lines[] = "### 模块 {$module}";
            $lines[] = '';
            // 段落内标题整体降两级，保持本文档层级一致
            $lines[] = preg_replace('/^(#{1,4})\s/m', '###$1 ', trim($body));
            $lines[] = '';
        }

        if (! $found) {
            $lines[] = '（暂无模块声明后续动作，模块可通过新增 `resources/kb/next-actions.md` 自助接入）';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * 剥离 YAML frontmatter，只保留正文
     */
    private function stripFrontmatter(string $content): string
    {
        return preg_replace('/\A---\s*\n.*?\n---\s*\n/s', '', $content) ?? $content;
    }
}
