<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Ai\Models\KbSuggestion;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\KbSuggestionService;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbDrafter;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbRegistry;

/**
 * secretary:kb:harvest — 收割 AI 沉淀的知识库提案（自学习闭环的定稿步骤）
 *
 * 生产端 suggest_kb_update 只提案（kb_suggestions 表），本命令在开发仓执行：
 * 拉取 pending 提案 → LLM 合并进目标 kb 文档（不可用时降级为结构化追加）→
 * 标记 adopted → git diff 人工审阅后提交，随版本发布生效。
 * 目标文档位于 vendor/ 内时不落盘（应到框架仓收割），归入待裁决清单。
 */
class SecretaryKbHarvestCommand extends Command
{
    protected $signature = 'secretary:kb:harvest
        {--limit=200 : 单次收割提案数上限}
        {--dry-run : 仅展示提案清单与分组，不合并不落盘}
        {--reject=* : 拒绝指定提案ID（可多次传入）}';

    protected $description = '收割知识库修改提案并合并进代码仓 kb 文档（AI 自学习定稿）';

    public function handle(
        KbSuggestionService $suggestions,
        SystemKbRegistry $registry,
        SystemKbDrafter $drafter,
    ): int {
        $rejectIds = array_map('intval', (array) $this->option('reject'));

        if ($rejectIds !== []) {
            $this->info('已拒绝 ' . $suggestions->markRejected($rejectIds) . ' 条提案');
        }

        $pending = $suggestions->listPending(max(1, (int) $this->option('limit')));

        if ($pending->isEmpty()) {
            $this->info('没有待收割的知识库提案');

            return self::SUCCESS;
        }

        // identity → 本仓可写的绝对路径（vendor 内文档不可写，应到框架仓收割）
        $docs = [];
        foreach ($registry->discover() as $doc) {
            $identity = $doc['module'] . '/' . basename($doc['path']);
            if (! str_contains($doc['absolute_path'], DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                $docs[$identity] = $doc['absolute_path'];
            }
        }

        /** @var array<string, list<KbSuggestion>> $mergeable */
        $mergeable = [];
        $manual = [];

        foreach ($pending as $suggestion) {
            $target = (string) $suggestion->target_doc;

            if ($target !== '' && isset($docs[$target])) {
                $mergeable[$target][] = $suggestion;
            } else {
                $manual[] = $suggestion;
            }
        }

        $this->renderOverview($mergeable, $manual);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        foreach ($mergeable as $identity => $group) {
            $path = $docs[$identity];
            $current = (string) file_get_contents($path);
            $merged = $this->mergeWithLlm($drafter, $current, $group) ?? $this->appendFallback($current, $group);

            file_put_contents($path, $merged);
            $suggestions->markAdopted(array_map(fn ($s) => (int) $s->suggestion_id, $group));
            $this->info('已合并 ' . count($group) . " 条提案 → {$identity}");
        }

        if ($manual !== []) {
            $this->warn('以上待裁决提案（target_doc 未指定/不存在/位于 vendor）需人工归置：补充 target_doc 后重跑，或用 --reject=ID 拒绝');
        }

        if ($mergeable !== []) {
            $this->comment('请用 git diff 审阅合并结果，确认后提交发布。');
        }

        return self::SUCCESS;
    }

    /**
     * LLM 合并：把提案内容融入现有文档，保持原结构与口吻；失败返回 null
     *
     * @param  list<KbSuggestion>  $group
     */
    private function mergeWithLlm(SystemKbDrafter $drafter, string $current, array $group): ?string
    {
        $proposals = '';
        foreach ($group as $s) {
            $proposals .= "- 用户问题：{$s->trigger_query}\n  建议补充：\n{$s->suggested_content}\n\n";
        }

        $merged = $drafter->draft(
            '你是系统知识库编辑。把「运营现场提案」融入「现有文档」：保持原文档结构、标题层级与 frontmatter 不变，'
            . '将提案内容归并到最合适的章节（必要时新增小节），去重、消歧、统一口吻；只输出合并后的完整 markdown，不要任何解释。',
            "## 现有文档\n\n{$current}\n\n## 运营现场提案\n\n{$proposals}",
        );

        // LLM 输出丢失 frontmatter 等结构性截断时视为失败，走降级追加
        if ($merged === null || mb_strlen($merged) < mb_strlen($current) * 0.5) {
            return null;
        }

        return $merged;
    }

    /**
     * 降级策略：LLM 不可用时结构化追加到文档末尾（git diff 仍可审）
     *
     * @param  list<KbSuggestion>  $group
     */
    private function appendFallback(string $current, array $group): string
    {
        $appendix = "\n\n## 运营现场补充（待整理）\n";

        foreach ($group as $s) {
            $appendix .= "\n### {$s->trigger_query}\n\n{$s->suggested_content}\n";
        }

        return rtrim($current) . "\n" . $appendix;
    }

    /**
     * @param  array<string, list<KbSuggestion>>  $mergeable
     * @param  list<KbSuggestion>  $manual
     */
    private function renderOverview(array $mergeable, array $manual): void
    {
        $rows = [];

        foreach ($mergeable as $identity => $group) {
            foreach ($group as $s) {
                $rows[] = [$s->suggestion_id, '自动合并', $identity, mb_substr($s->trigger_query, 0, 40)];
            }
        }

        foreach ($manual as $s) {
            $rows[] = [$s->suggestion_id, '待裁决', $s->target_doc !== '' ? $s->target_doc : '(未指定)', mb_substr($s->trigger_query, 0, 40)];
        }

        $this->table(['提案ID', '处置', '目标文档', '触发问题'], $rows);
    }
}
