<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use Illuminate\Support\Collection;
use MultiTenantSaas\Modules\Ai\Models\KbSuggestion;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 知识库提案服务（AI 自学习回流通道）
 *
 * 生产端：suggest_kb_update 工具（L2 确认后）经 submit 沉淀提案；
 * 开发端：secretary:kb:harvest 经 listPending / markAdopted 收割定稿。
 * 铁律：提案只入 DB，绝不在运行时写 kb 文件——代码仓是唯一权威源。
 */
class KbSuggestionService
{
    /**
     * 提交提案（幂等去重：同租户同目标文档下相同触发问题的 pending 提案只更新不新建）
     */
    public function submit(int $tenantId, array $data): KbSuggestion
    {
        $triggerQuery = mb_substr(trim((string) ($data['trigger_query'] ?? '')), 0, 500);

        $existing = KbSuggestion::where('tenant_id', $tenantId)
            ->where('status', KbSuggestion::STATUS_PENDING)
            ->where('target_doc', (string) ($data['target_doc'] ?? ''))
            ->where('trigger_query', $triggerQuery)
            ->first();

        if ($existing) {
            $existing->update(['suggested_content' => (string) ($data['suggested_content'] ?? '')]);

            return $existing;
        }

        return KbSuggestion::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $data['conversation_id'] ?? null,
            'target_module' => mb_substr((string) ($data['target_module'] ?? ''), 0, 100),
            'target_doc' => mb_substr((string) ($data['target_doc'] ?? ''), 0, 200),
            'trigger_query' => $triggerQuery,
            'suggested_content' => (string) ($data['suggested_content'] ?? ''),
            'status' => KbSuggestion::STATUS_PENDING,
        ]);
    }

    /**
     * 待收割提案（跨租户：系统知识是平台级资产，CLI 收割侧专用）
     *
     * @return Collection<int, KbSuggestion>
     */
    public function listPending(int $limit = 200): Collection
    {
        return TenantScope::allowUnscoped(
            fn () => KbSuggestion::where('status', KbSuggestion::STATUS_PENDING)
                ->orderBy('created_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * 标记已采纳
     *
     * @param  list<int>  $suggestionIds
     */
    public function markAdopted(array $suggestionIds): int
    {
        return $this->resolve($suggestionIds, KbSuggestion::STATUS_ADOPTED);
    }

    /**
     * 标记已拒绝
     *
     * @param  list<int>  $suggestionIds
     */
    public function markRejected(array $suggestionIds): int
    {
        return $this->resolve($suggestionIds, KbSuggestion::STATUS_REJECTED);
    }

    private function resolve(array $suggestionIds, string $status): int
    {
        if ($suggestionIds === []) {
            return 0;
        }

        return TenantScope::allowUnscoped(
            fn () => KbSuggestion::whereIn('suggestion_id', $suggestionIds)
                ->where('status', KbSuggestion::STATUS_PENDING)
                ->update(['status' => $status, 'resolved_at' => now()])
        );
    }
}
