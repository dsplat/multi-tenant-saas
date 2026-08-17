<?php

namespace MultiTenantSaas\Modules\Campaign\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Campaign\Events\CampaignPlanScheduled;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;

/**
 * campaign_plan_commit — 定稿并编译计划（L2，需确认门）
 *
 * 从 DB 取 CampaignPlan (status=planning) 的 plan_doc
 * → PlanCompiler::validate → 有错误返回 error
 * → PlanCompiler::compile(plan, anchor_times) → 生成 campaign_tasks
 * → plan status: planning → scheduled
 * → 返回 {plan_id, status, tasks_count, timeline_preview}
 *
 * L2 风险等级：commit 会生成实际的调度任务，需用户确认后执行。
 */
class CampaignPlanCommitTool implements ToolHandlerContract
{
    public function __construct(
        private readonly PlanCompiler $compiler,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);
        $anchorTimes = (array) ($arguments['anchor_times'] ?? []);

        if ($planId <= 0) {
            return ['error' => true, 'message' => '请提供 plan_id'];
        }

        // 1. 取计划
        $plan = CampaignPlan::where('plan_id', $planId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($plan === null) {
            return ['error' => true, 'message' => "计划 [{$planId}] 不存在"];
        }

        if ($plan->status !== CampaignPlan::STATUS_PLANNING) {
            return ['error' => true, 'message' => "计划状态为 [{$plan->status}]，只有 planning 状态可提交"];
        }

        $planDoc = $plan->plan_doc;

        if (! is_array($planDoc) || empty($planDoc['phases'])) {
            return ['error' => true, 'message' => '计划文档为空或无 phases，请先使用 campaign_plan_draft 生成'];
        }

        // 2. 校验
        $errors = $this->compiler->validate($planDoc);

        if ($errors !== []) {
            return [
                'error' => true,
                'message' => '计划校验不通过',
                'validation_errors' => $errors,
            ];
        }

        // 2.5 锚点一次性预检：结构化返回全部缺失锚点，LLM 可一轮补齐后重试
        $missingAnchors = array_values(array_diff(
            $this->compiler->collectRequiredAnchors($planDoc),
            array_keys($anchorTimes),
        ));
        if ($missingAnchors !== []) {
            return [
                'error' => true,
                'message' => '锚点时间缺失，请在 anchor_times 参数中一次性提供全部锚点后重新提交（缺少的时间先向用户确认）',
                'missing_anchors' => $missingAnchors,
            ];
        }

        // 3. 编译
        try {
            $this->compiler->compile($plan, $anchorTimes);
        } catch (\Throwable $e) {
            return [
                'error' => true,
                'message' => '编译失败：' . $e->getMessage(),
            ];
        }

        // 4. 定稿即自动跟踪（天然脉络：operator 已在确认门确认，本身即跟踪授权，
        // 免二次确认），进入每日巡检与会话摘要注入范围
        $plan->refresh();
        $plan->forceFill([
            'metadata' => array_merge((array) $plan->metadata, [
                'tracked' => true,
                'tracked_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        // 5. 返回结果
        $tasks = $plan->tasks()->orderBy('scheduled_at')->get();

        // 6. 派发定稿排期事件：项目层可监听同步创建营销活动实体等扩展
        event(new CampaignPlanScheduled(
            tenantId: (int) $plan->tenant_id,
            planId: (int) $plan->plan_id,
            title: (string) ($planDoc['title'] ?? '未命名活动'),
            startsAt: $tasks->min('scheduled_at')?->toDateTimeString(),
            endsAt: $tasks->max('scheduled_at')?->toDateTimeString(),
            tasksCount: $tasks->count(),
        ));

        return [
            'plan_id' => $plan->plan_id,
            'status' => $plan->status,
            'tasks_count' => $tasks->count(),
            'timeline_preview' => $tasks->map(fn ($task) => [
                'key' => $task->task_key,
                'title' => $task->title,
                'scheduled_at' => $task->scheduled_at?->toDateTimeString(),
                'trigger_type' => $task->trigger_type,
                'execution_mode' => $task->execution_mode,
            ])->values()->toArray(),
        ];
    }
}
