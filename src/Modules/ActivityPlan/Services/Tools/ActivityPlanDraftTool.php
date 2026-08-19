<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services\Tools;

use MultiTenantSaas\Modules\Ai\Jobs\ExecuteAiTaskJob;
use MultiTenantSaas\Modules\Ai\Models\AiTask;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;

/**
 * activity_plan_draft — AI 共创计划方案（L1，任务化长工具）
 *
 * 任务化架构（task/queue 跟踪机制）：
 * - 本工具只做毫秒级提交：校验入参 → 创建 AiTask(pending) → dispatch
 *   ExecuteAiTaskJob → 立即返回 {action:'await_task', task_id}
 * - Node 引擎识别 await_task 后在流内短连接轮询 tasks/status，
 *   LLM 与前端无感（轮询期间心跳帧保活）
 * - 重模型生成（plan_doc JSON）在 queue worker 内由
 *   ActivityPlanDraftTaskHandler 执行，不受连接超时约束
 *
 * 有 plan_id：修订现有 ActivityPlan (status=planning)
 * 无 plan_id + 有 playbook_key：取 playbook skeleton 作为初始骨架
 *
 * 设计理由：存 DB 避免 commit 工具需要 LLM 传入巨量 JSON（贵且易错），
 * 多次 draft 修订自然版本化。
 */
class ActivityPlanDraftTool implements ToolHandlerContract
{
    public function __construct(
        private readonly ToolConversationContext $conversationContext,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);
        $playbookKey = (string) ($arguments['playbook_key'] ?? '');
        $userInput = (string) ($arguments['user_input'] ?? '');
        $anchorType = $arguments['anchor_type'] ?? null;
        $anchorId = $arguments['anchor_id'] ?? null;

        if ($userInput === '') {
            return ['error' => true, 'message' => '请提供 user_input 描述活动需求'];
        }

        // 快速失败校验留在同步路径（毫秒级 DB 查询），避免无效任务入队
        if ($planId > 0) {
            $exists = ActivityPlan::where('plan_id', $planId)
                ->where('tenant_id', $tenantId)
                ->where('status', ActivityPlan::STATUS_PLANNING)
                ->exists();

            if (! $exists) {
                return ['error' => true, 'message' => "计划 [{$planId}] 不存在或不在 planning 状态"];
            }
        }

        // 提交任务：AiTask 主键由 IdGenerator 生成（HasGlobalId），
        // tenant_id 由 BelongsToTenant 从租户上下文自动填充
        $task = AiTask::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $this->conversationContext->get(),
            'type' => 'activity_plan_draft',
            'status' => AiTask::STATUS_PENDING,
            'payload' => [
                'plan_id' => $planId,
                'playbook_key' => $playbookKey,
                'user_input' => $userInput,
                'anchor_type' => $anchorType,
                'anchor_id' => $anchorId,
            ],
        ]);

        ExecuteAiTaskJob::dispatch((int) $task->task_id, $tenantId);

        // await_task 协议：Node 引擎识别后进入流内短连接轮询（LLM/前端无感）
        return [
            'action' => 'await_task',
            'task_id' => (int) $task->task_id,
            'message' => '活动策划方案正在后台生成中（通常需要数十秒），请稍候',
        ];
    }
}
