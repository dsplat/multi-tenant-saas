<?php

namespace MultiTenantSaas\Modules\Campaign\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;

/**
 * Campaign 任务执行器（docs/event-plan.md Phase 0）
 *
 * Phase 0 范围：
 * - action.type=tool → 占位符替换 → ToolRegistry::execute
 * - assignee_type=human → 保持 running 等 complete API
 * - agent ReAct / task_chain / on_event 执行为 Phase 2，遇到置 failed
 */
class CampaignTaskExecutor
{
    public function __construct(
        private readonly ToolRegistryContract $toolRegistry,
    ) {}

    /**
     * 执行任务（置 running → 分派 → 置终态）
     */
    public function execute(CampaignTask $task): void
    {
        $task->update([
            'status' => CampaignTask::STATUS_RUNNING,
            'executed_at' => Carbon::now(),
        ]);

        $action = $task->action ?? [];
        $actionType = $action['type'] ?? '';

        try {
            match ($actionType) {
                'tool' => $this->executeTool($task, $action),
                'task_chain' => $this->unsupported($task, 'task_chain 组合为 Phase 2'),
                'agent_react' => $this->unsupported($task, 'agent ReAct 为 Phase 2'),
                default => $this->executeHumanOrSystem($task),
            };
        } catch (\Throwable $e) {
            Log::error('[Campaign] 任务执行异常', [
                'task_id' => $task->task_id,
                'task_key' => $task->task_key,
                'error' => $e->getMessage(),
            ]);

            $task->update([
                'status' => CampaignTask::STATUS_FAILED,
                'output' => ['error' => $e->getMessage()],
            ]);
        }
    }

    /**
     * 工具执行：占位符替换 → ToolRegistry::execute → 写回 output
     */
    private function executeTool(CampaignTask $task, array $action): void
    {
        $slug = $action['tool'] ?? '';
        $args = $action['args'] ?? [];

        // 占位符替换
        $args = $this->resolvePlaceholders($args, $task);

        $result = $this->toolRegistry->execute($slug, $args, (int) $task->tenant_id);

        if (is_array($result) && ($result['error'] ?? false)) {
            $task->update([
                'status' => CampaignTask::STATUS_FAILED,
                'output' => ['error' => $result['message'] ?? '工具执行失败', 'slug' => $slug],
            ]);

            return;
        }

        $task->update([
            'status' => CampaignTask::STATUS_DONE,
            'output' => is_array($result) ? $result : ['result' => $result],
        ]);
    }

    /**
     * human / system 任务：保持 running 等外部 complete
     *
     * assignee_type=human 的任务不自动执行，等待管理 API complete 置 done。
     * assignee_type=system 且无 action.type 的视为空操作直接 done。
     */
    private function executeHumanOrSystem(CampaignTask $task): void
    {
        if ($task->assignee_type === 'human') {
            // 保持 running，等 complete API
            return;
        }

        // system 无具体 action → 直接完成
        $task->update([
            'status' => CampaignTask::STATUS_DONE,
            'output' => ['message' => '无操作（system 空任务）'],
        ]);
    }

    /**
     * 不支持的执行类型 → 置 failed
     */
    private function unsupported(CampaignTask $task, string $reason): void
    {
        $task->update([
            'status' => CampaignTask::STATUS_FAILED,
            'output' => ['error' => "暂不支持：{$reason}"],
        ]);
    }

    /**
     * 占位符替换：{{task.<key>.output}} / {{plan.*}}
     *
     * 从同 plan 已 done 任务的 output 与 plan_doc 字段取值。
     * 递归处理 args 中的字符串值。
     */
    private function resolvePlaceholders(mixed $value, CampaignTask $task): mixed
    {
        if (is_string($value)) {
            return $this->replaceString($value, $task);
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->resolvePlaceholders($v, $task), $value);
        }

        return $value;
    }

    private function replaceString(string $str, CampaignTask $task): string
    {
        // {{task.<key>.output}} → 前序任务 output JSON
        $str = preg_replace_callback('/\{\{task\.([a-zA-Z0-9_-]+)\.output\}\}/', function ($m) use ($task) {
            $depTask = CampaignTask::where('plan_id', $task->plan_id)
                ->where('task_key', $m[1])
                ->where('status', CampaignTask::STATUS_DONE)
                ->first();

            if (! $depTask || ! $depTask->output) {
                return '';
            }

            return is_string($depTask->output) ? $depTask->output : json_encode($depTask->output, JSON_UNESCAPED_UNICODE);
        }, $str);

        // {{plan.<field>}} → plan_doc 顶层字段
        $str = preg_replace_callback('/\{\{plan\.([a-zA-Z0-9_-]+)\}\}/', function ($m) use ($task) {
            $plan = $task->plan;
            if (! $plan) {
                return '';
            }

            $planDoc = $plan->plan_doc ?? [];
            $fieldValue = $planDoc[$m[1]] ?? '';

            return is_scalar($fieldValue) ? (string) $fieldValue : json_encode($fieldValue, JSON_UNESCAPED_UNICODE);
        }, $str);

        return $str ?? '';
    }
}
