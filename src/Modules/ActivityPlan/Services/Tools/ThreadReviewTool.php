<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services\Tools;

use MultiTenantSaas\Contracts\ThreadAssetProbeContract;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;

/**
 * thread_review — 工作脉络全貌快照（L1 只读，项目大脑 Phase 2a）
 *
 * 输入 anchor_type + anchor_id（任意业务对象）或 plan_id（无锚点线索），
 * 聚合脉络事实供 LLM 推理遗漏：
 *  - 线索上的计划/任务状态分布（无计划也返回——"连策划都没做"本身是事实）
 *  - 关联资产探测结果（下游经 ThreadAssetProbeContract 注册，含锚点完整度）
 *  - 关联会话摘要（同主题历史对话，首版主题匹配，Phase 4 升级精确关联）
 *
 * 不做规则判断，只聚合事实——遗漏判断交给 LLM 结合能力图谱推理。
 */
class ThreadReviewTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);
        $anchorType = trim((string) ($arguments['anchor_type'] ?? ''));
        $anchorId = (int) ($arguments['anchor_id'] ?? 0);

        if ($planId <= 0 && ($anchorType === '' || $anchorId <= 0)) {
            return ['error' => true, 'message' => '请提供 plan_id 或 anchor_type + anchor_id'];
        }

        // 定位线索上的计划集合（plan_id 直取；锚点则取全部挂靠计划）
        $query = ActivityPlan::where('tenant_id', $tenantId);
        if ($planId > 0) {
            $query->where('plan_id', $planId);
        } else {
            $query->where('anchor_type', $anchorType)->where('anchor_id', $anchorId);
        }
        $plans = $query->orderByDesc('updated_at')->get();

        if ($planId > 0 && $plans->isEmpty()) {
            return ['error' => true, 'message' => "计划 [{$planId}] 不存在"];
        }

        // plan_id 入口时从计划回填锚点，供资产探测使用
        $latest = $plans->first();
        if ($anchorType === '' && $latest?->anchor_type !== null && $latest?->anchor_type !== '') {
            $anchorType = (string) $latest->anchor_type;
            $anchorId = (int) $latest->anchor_id;
        }

        return [
            'thread' => [
                'anchor' => $anchorType !== '' ? ['type' => $anchorType, 'id' => $anchorId] : null,
                'tracked' => (bool) data_get($latest?->metadata, 'tracked', false),
                'plans_count' => $plans->count(),
            ],
            'plans' => $plans->map(fn (ActivityPlan $plan) => $this->planSnapshot($plan))->values()->toArray(),
            'assets' => $this->probeAssets($anchorType, $anchorId, $tenantId),
            'conversations' => $this->relatedConversations($tenantId, $latest),
            'note' => $plans->isEmpty()
                ? '该脉络尚无任何计划（策划环节还未开始，可建议先 activity_plan_draft）'
                : null,
        ];
    }

    /**
     * 单计划快照：基本信息 + 任务状态分布 + 逾期数 + 巡检结果
     *
     * @return array<string, mixed>
     */
    private function planSnapshot(ActivityPlan $plan): array
    {
        $tasks = $plan->tasks()->get(['status', 'task_key', 'title', 'scheduled_at']);

        $overdue = $tasks
            ->whereIn('status', [ActivityTask::STATUS_PENDING, ActivityTask::STATUS_AWAITING_CONFIRM])
            ->filter(fn ($task) => $task->scheduled_at !== null && $task->scheduled_at->isPast());

        return [
            'plan_id' => $plan->plan_id,
            'title' => $plan->plan_doc['title'] ?? '（未命名）',
            'status' => $plan->status,
            'tracked' => (bool) data_get($plan->metadata, 'tracked', false),
            'updated_at' => $plan->updated_at?->toDateTimeString(),
            'tasks_total' => $tasks->count(),
            'tasks_by_status' => $tasks->countBy('status')->toArray(),
            'overdue_tasks' => $overdue->map(fn ($task) => [
                'key' => $task->task_key,
                'title' => $task->title,
                'scheduled_at' => $task->scheduled_at?->toDateTimeString(),
            ])->values()->toArray(),
            'health' => data_get($plan->metadata, 'health'),
        ];
    }

    /**
     * 聚合下游注册的资产探测结果（无锚点或无适用探测器时返回说明）
     *
     * @return array<string, mixed>
     */
    private function probeAssets(string $anchorType, int $anchorId, int $tenantId): array
    {
        if ($anchorType === '' || $anchorId <= 0) {
            return ['note' => '无锚点对象，跳过资产探测'];
        }

        $facts = [];
        foreach ((array) config('ai.brain.asset_probes', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $probe = app($class);
            if (! $probe instanceof ThreadAssetProbeContract || ! $probe->supports($anchorType)) {
                continue;
            }

            try {
                $facts = array_merge($facts, $probe->probe($anchorType, $anchorId, $tenantId));
            } catch (\Throwable $e) {
                // 单个探测器异常不影响其余事实聚合
                $facts[$class] = ['error' => $e->getMessage()];
            }
        }

        return $facts === [] ? ['note' => '该锚点类型暂无资产探测器'] : $facts;
    }

    /**
     * 关联会话摘要（首版按计划标题主题匹配，Phase 4 升级为精确锚点关联）
     *
     * @return list<array<string, mixed>>
     */
    private function relatedConversations(int $tenantId, ?ActivityPlan $plan): array
    {
        $title = trim((string) ($plan?->plan_doc['title'] ?? ''));
        if ($title === '') {
            return [];
        }

        try {
            return AgentConversation::where('tenant_id', $tenantId)
                ->where('channel', 'assistant')
                ->where(fn ($q) => $q
                    ->where('subject', 'like', "%{$title}%")
                    ->orWhere('summary', 'like', "%{$title}%"))
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(['conversation_id', 'subject', 'summary', 'updated_at'])
                ->map(fn ($conv) => [
                    'conversation_id' => $conv->conversation_id,
                    'subject' => $conv->subject,
                    'summary' => $conv->summary !== null ? mb_substr((string) $conv->summary, 0, 200) : null,
                    'updated_at' => $conv->updated_at?->toDateTimeString(),
                ])->values()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
