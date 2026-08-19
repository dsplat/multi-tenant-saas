<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;

/**
 * activity_status — 查询计划状态与任务进度（L1）
 *
 * 返回计划概览 + 任务列表（含状态/时间/产出摘要）+ 待确认数。
 * 供秘书在对话中告知用户活动执行进度。
 */
class ActivityStatusTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);

        if ($planId <= 0) {
            return ['error' => true, 'message' => '请提供 plan_id'];
        }

        $plan = ActivityPlan::where('plan_id', $planId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($plan === null) {
            return ['error' => true, 'message' => "计划 [{$planId}] 不存在"];
        }

        $tasks = $plan->tasks()->orderBy('scheduled_at')->get();

        $pendingConfirms = $tasks->where('status', ActivityTask::STATUS_AWAITING_CONFIRM)->count();

        return [
            'plan' => [
                'plan_id' => $plan->plan_id,
                'status' => $plan->status,
                'title' => $plan->plan_doc['title'] ?? '（未命名）',
                'playbook_key' => $plan->playbook_key,
                'created_at' => $plan->created_at?->toDateTimeString(),
            ],
            'tasks' => $tasks->map(fn (ActivityTask $task) => [
                'key' => $task->task_key,
                'title' => $task->title,
                'status' => $task->status,
                'scheduled_at' => $task->scheduled_at?->toDateTimeString(),
                'executed_at' => $task->executed_at?->toDateTimeString(),
                'output_summary' => $this->summarizeOutput($task->output),
            ])->values()->toArray(),
            'pending_confirms' => $pendingConfirms,
        ];
    }

    /**
     * 产出摘要（截取前 200 字符，避免大量数据）
     */
    private function summarizeOutput(mixed $output): ?string
    {
        if ($output === null) {
            return null;
        }

        $text = is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE);

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }
}
