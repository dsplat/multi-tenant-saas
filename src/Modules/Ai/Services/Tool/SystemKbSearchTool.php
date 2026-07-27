<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbSearchService;

/**
 * system_kb_search — 混合检索系统知识库
 *
 * 小秘书回答"系统怎么用/功能在哪/业务流"的事实来源。
 * 返回带模块/文档来源的片段，AI 须依据片段作答（答不出就承认）。
 */
class SystemKbSearchTool implements ToolHandlerContract
{
    public function __construct(
        private readonly SystemKbSearchService $searchService,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return ['error' => true, 'message' => 'query 不能为空'];
        }

        $topK = min(10, max(1, (int) ($arguments['top_k'] ?? 5)));

        $results = $this->searchService->search($query, $topK);

        return [
            'query' => $query,
            'total' => count($results),
            'results' => $results,
        ];
    }
}
