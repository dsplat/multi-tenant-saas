<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\KbSuggestionService;

/**
 * suggest_kb_update — 提交系统知识库修改提案（L2，需用户确认）
 *
 * AI 自学习回流通道：小秘书检索不到答案时把知识缺口沉淀为提案。
 * 只写 kb_suggestions 表，绝不直改 kb 文件——定稿由开发侧
 * secretary:kb:harvest 收割进代码仓后随版本发布。
 */
class SuggestKbUpdateTool implements ToolHandlerContract
{
    public function __construct(
        private readonly KbSuggestionService $suggestionService,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $triggerQuery = trim((string) ($arguments['trigger_query'] ?? ''));
        $suggestedContent = trim((string) ($arguments['suggested_content'] ?? ''));

        if ($triggerQuery === '' || $suggestedContent === '') {
            return ['error' => true, 'message' => 'trigger_query 与 suggested_content 不能为空'];
        }

        $suggestion = $this->suggestionService->submit($tenantId, [
            'target_module' => trim((string) ($arguments['target_module'] ?? '')),
            'target_doc' => trim((string) ($arguments['target_doc'] ?? '')),
            'trigger_query' => $triggerQuery,
            'suggested_content' => $suggestedContent,
        ]);

        return [
            'suggestion_id' => $suggestion->suggestion_id,
            'status' => $suggestion->status,
            'message' => '知识库修改提案已提交，将由平台评审后随版本发布生效',
        ];
    }
}
