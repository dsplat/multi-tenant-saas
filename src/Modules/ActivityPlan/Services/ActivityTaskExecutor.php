<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\HeadlessAgentService;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRunner;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;

/**
 * 活动任务执行器（docs/event-plan.md Phase 0 + Phase 2）
 *
 * Phase 0：
 * - action.type=tool → 占位符替换 → ToolRegistry::execute
 * - assignee_type=human → 保持 running 等 complete API
 *
 * Phase 2：
 * - action.type=task_chain → TaskChainRunner::start + 循环 advance（forceL2）
 * - action.type=agent_react → HeadlessAgentService::execute
 */
class ActivityTaskExecutor
{
    public function __construct(
        private readonly ToolRegistryContract $toolRegistry,
        private readonly TaskChainRunner $runner,
        private readonly HeadlessAgentService $headless,
    ) {}

    /**
     * 执行任务（置 running → 分派 → 置终态）
     */
    public function execute(ActivityTask $task): void
    {
        $task->update([
            'status' => ActivityTask::STATUS_RUNNING,
            'executed_at' => Carbon::now(),
        ]);

        $action = $task->action ?? [];
        $actionType = $action['type'] ?? '';

        try {
            match ($actionType) {
                'tool' => $this->executeTool($task, $action),
                'task_chain' => $this->executeTaskChain($task, $action),
                'agent_react' => $this->executeAgentReact($task, $action),
                default => $this->executeHumanOrSystem($task),
            };
        } catch (\Throwable $e) {
            Log::error('[ActivityPlan] 任务执行异常', [
                'task_id' => $task->task_id,
                'task_key' => $task->task_key,
                'error' => $e->getMessage(),
            ]);

            $task->update([
                'status' => ActivityTask::STATUS_FAILED,
                'output' => ['error' => $e->getMessage()],
            ]);
        }
    }

    /**
     * 工具执行：占位符替换 → ToolRegistry::execute → 写回 output
     */
    private function executeTool(ActivityTask $task, array $action): void
    {
        $slug = $action['tool'] ?? '';
        $args = $action['args'] ?? [];

        // 占位符替换
        $args = $this->resolvePlaceholders($args, $task);

        $result = $this->toolRegistry->execute($slug, $args, (int) $task->tenant_id);

        if (is_array($result) && ($result['error'] ?? false)) {
            $task->update([
                'status' => ActivityTask::STATUS_FAILED,
                'output' => ['error' => $result['message'] ?? '工具执行失败', 'slug' => $slug],
            ]);

            return;
        }

        $task->update([
            'status' => ActivityTask::STATUS_DONE,
            'output' => is_array($result) ? $result : ['result' => $result],
        ]);
    }

    /**
     * task_chain 执行：启动链 + 循环 advance 直到完成/失败
     *
     * forceL2：activity plan 本身已 L2 确认（commit 过确认门），链内 L2 工具视为预审批
     */
    private function executeTaskChain(ActivityTask $task, array $action): void
    {
        $chainKey = $action['chain_key'] ?? '';

        if ($chainKey === '') {
            $task->update([
                'status' => ActivityTask::STATUS_FAILED,
                'output' => ['error' => 'action.chain_key 缺失'],
            ]);

            return;
        }

        $tenantId = (int) $task->tenant_id;
        $presetArgs = $action['args'] ?? [];

        // 启动链（conversationId=null，活动计划触发无归属会话）
        $state = $this->runner->start($chainKey, $tenantId, null, forceL2: true);

        if ($state['error'] ?? false) {
            $task->update([
                'status' => ActivityTask::STATUS_FAILED,
                'output' => ['error' => $state['message'] ?? '链启动失败'],
            ]);

            return;
        }

        // 循环 advance 直到终态
        $maxIterations = 20;

        for ($i = 0; $i < $maxIterations; $i++) {
            $status = $state['status'] ?? '';

            if ($status === 'completed') {
                $task->update([
                    'status' => ActivityTask::STATUS_DONE,
                    'output' => $state,
                ]);

                return;
            }

            if ($status === 'failed') {
                $task->update([
                    'status' => ActivityTask::STATUS_FAILED,
                    'output' => $state,
                ]);

                return;
            }

            // 为 input 步提供预定义 args
            $stepInput = [];
            $currentStep = $state['current_step'] ?? 0;

            if (isset($presetArgs["step_{$currentStep}"])) {
                $stepInput = $presetArgs["step_{$currentStep}"];
            }

            $state = $this->runner->advance(
                $state['run_id'],
                $tenantId,
                $stepInput,
                [],
                false,
                forceL2: true
            );

            if ($state['error'] ?? false) {
                $task->update([
                    'status' => ActivityTask::STATUS_FAILED,
                    'output' => $state,
                ]);

                return;
            }
        }

        // 超过最大迭代次数
        $task->update([
            'status' => ActivityTask::STATUS_FAILED,
            'output' => ['error' => '任务链执行超过最大迭代次数', 'last_state' => $state],
        ]);
    }

    /**
     * agent_react 执行：调用 HeadlessAgentService 执行无用户交互的短 ReAct 会话
     */
    private function executeAgentReact(ActivityTask $task, array $action): void
    {
        $role = $action['role'] ?? '';
        $promptTemplate = $action['prompt'] ?? '';

        if ($role === '' || $promptTemplate === '') {
            $task->update([
                'status' => ActivityTask::STATUS_FAILED,
                'output' => ['error' => 'agent_react 缺少 role 或 prompt'],
            ]);

            return;
        }

        // 解析 prompt 中的占位符
        $resolvedPrompt = $this->replaceString($promptTemplate, $task);

        $result = $this->headless->execute($role, $resolvedPrompt, (int) $task->tenant_id);

        if ($result->partial) {
            $task->update([
                'status' => ActivityTask::STATUS_FAILED,
                'output' => ['error' => 'agent_react 执行失败（partial）: ' . $result->error, 'tool_calls_log' => $result->toolCallsLog],
            ]);

            return;
        }

        $task->update([
            'status' => ActivityTask::STATUS_DONE,
            'output' => ['text' => $result->text, 'tool_calls_log' => $result->toolCallsLog],
        ]);
    }

    /**
     * human / system 任务：保持 running 等外部 complete
     *
     * assignee_type=human 的任务不自动执行，等待管理 API complete 置 done。
     * assignee_type=user（学员侧）不自动执行，等待学员完成（activity_task_completions）。
     * assignee_type=system 且无 action.type 的视为空操作直接 done。
     */
    private function executeHumanOrSystem(ActivityTask $task): void
    {
        if (in_array($task->assignee_type, [ActivityTask::ASSIGNEE_HUMAN, ActivityTask::ASSIGNEE_USER], true)) {
            // 保持 running，等 complete API / 学员完成
            return;
        }

        // system 无具体 action → 直接完成
        $task->update([
            'status' => ActivityTask::STATUS_DONE,
            'output' => ['message' => '无操作（system 空任务）'],
        ]);
    }

    /**
     * 占位符替换：{{task.<key>.output}} / {{plan.*}}
     *
     * 从同 plan 已 done 任务的 output 与 plan_doc 字段取值。
     * 递归处理 args 中的字符串值。
     */
    private function resolvePlaceholders(mixed $value, ActivityTask $task): mixed
    {
        if (is_string($value)) {
            return $this->replaceString($value, $task);
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->resolvePlaceholders($v, $task), $value);
        }

        return $value;
    }

    private function replaceString(string $str, ActivityTask $task): string
    {
        // {{task.<key>.output}} → 前序任务 output JSON
        $str = preg_replace_callback('/\{\{task\.([a-zA-Z0-9_-]+)\.output\}\}/', function ($m) use ($task) {
            $depTask = ActivityTask::where('plan_id', $task->plan_id)
                ->where('task_key', $m[1])
                ->where('status', ActivityTask::STATUS_DONE)
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
