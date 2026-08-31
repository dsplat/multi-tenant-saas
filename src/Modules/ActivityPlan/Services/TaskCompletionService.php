<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services;

use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTaskCompletion;

/**
 * 学员侧任务完成服务（assignee_type='user'）
 *
 * 排期引擎原语义为"运营动作排期"；本服务扩展学员学习任务语义：
 * 每日打卡任务/训练营营期任务由学员完成，完成记录幂等落
 * activity_task_completions（唯一约束 tenant_id+task_id+user_id）。
 */
class TaskCompletionService
{
    /**
     * 记录学员完成任务（幂等：重复完成返回既有记录）
     *
     * @param array $output 完成产出（学习时长/提交 ID 等，业务自定义）
     */
    public function complete(ActivityTask $task, int $userId, array $output = []): ActivityTaskCompletion
    {
        return ActivityTaskCompletion::firstOrCreate(
            [
                'tenant_id' => (int) $task->tenant_id,
                'task_id'   => (int) $task->task_id,
                'user_id'   => $userId,
            ],
            [
                'completed_at' => now(),
                'output'       => $output ?: null,
            ]
        );
    }

    /**
     * 查询计划下学员侧任务及完成状态
     *
     * @return array<int, array{task: ActivityTask, completed: bool, completed_at: string|null}>
     */
    public function tasksForUser(int $tenantId, int $planId, int $userId): array
    {
        $tasks = ActivityTask::where('tenant_id', $tenantId)
            ->where('plan_id', $planId)
            ->where('assignee_type', ActivityTask::ASSIGNEE_USER)
            ->whereNotIn('status', [ActivityTask::STATUS_CANCELLED, ActivityTask::STATUS_SKIPPED])
            ->orderBy('scheduled_at')
            ->get();

        $completionMap = ActivityTask::completionMapFor(
            $tenantId,
            $userId,
            $tasks->pluck('task_id')->all()
        );

        return $tasks->map(function (ActivityTask $task) use ($completionMap) {
            $completion = $completionMap[$task->task_id] ?? null;

            return [
                'task'         => $task,
                'completed'    => $completion !== null,
                'completed_at' => $completion?->completed_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * 计划下学员侧任务完成数（用于进度/证书触发判定）
     */
    public function completedCount(int $tenantId, int $planId, int $userId): int
    {
        $taskIds = ActivityTask::where('tenant_id', $tenantId)
            ->where('plan_id', $planId)
            ->where('assignee_type', ActivityTask::ASSIGNEE_USER)
            ->pluck('task_id');

        return ActivityTaskCompletion::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('task_id', $taskIds)
            ->count();
    }
}
