<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services\Tools;

use MultiTenantSaas\Modules\Ai\Models\AiTask;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\ActivityPlan\Events\ActivityPlanScheduled;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Services\PlanCompiler;

/**
 * activity_plan_commit — 定稿并编译计划（L2，需确认门）
 *
 * 从 DB 取 ActivityPlan (status=planning) 的 plan_doc
 * → PlanCompiler::validate → 有错误返回 error
 * → PlanCompiler::compile(plan, anchor_times) → 生成 activity_tasks
 * → plan status: planning → scheduled
 * → 返回 {plan_id, status, tasks_count, timeline_preview}
 *
 * L2 风险等级：commit 会生成实际的调度任务，需用户确认后执行。
 */
class ActivityPlanCommitTool implements ToolHandlerContract
{
    public function __construct(
        private readonly PlanCompiler $compiler,
        private readonly ?ToolConversationContext $conversationContext = null,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);
        $anchorTimes = (array) ($arguments['anchor_times'] ?? []);

        // 1. 取计划：传入 plan_id 有效则直取；无效/缺失时机械兑底。
        // 背景：前端跨轮上行历史为纯文本，draft 工具结果（含 plan_id）
        // 不随历史传递，新轮定稿时模型易幻觉 plan_id（如传 1）。
        $plan = $planId > 0
            ? ActivityPlan::where('plan_id', $planId)->where('tenant_id', $tenantId)->first()
            : null;

        if ($plan === null) {
            $plan = $this->resolveFallbackPlan($tenantId);
        }

        if ($plan === null) {
            return $this->planNotFoundError($planId, $tenantId);
        }

        if ($plan->status !== ActivityPlan::STATUS_PLANNING) {
            return ['error' => true, 'message' => "计划状态为 [{$plan->status}]，只有 planning 状态可提交"];
        }

        $planDoc = $plan->plan_doc;

        if (! is_array($planDoc) || empty($planDoc['phases'])) {
            return ['error' => true, 'message' => '计划文档为空或无 phases，请先使用 activity_plan_draft 生成'];
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
        event(new ActivityPlanScheduled(
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
            // 下一步指引（确定性事实源，不依赖模型自由发挥）：定稿后标准动作是
            // 营销内容与物料准备；每项排期任务到点时系统会在对话中提示确认执行
            'next_action' => '定稿成功。向用户播报结果后，主动引导下一步：活动进入「营销内容准备」阶段——'
                . '如优惠券规则配置、推广文案、海报素材等，询问用户是否现在就开始准备（你能协助生成文案与素材）；'
                . '并说明每项排期任务到达时间点时会在对话中提示确认执行，可在「活动日历」查看排期全貌。',
        ];
    }

    /**
     * plan_id 无效时的机械兑底：取当前会话最近一次成功的 draft 任务结果里的 plan_id。
     * 无会话上下文（运维直调等）或无 draft 任务时返回 null 交由错误分支。
     */
    private function resolveFallbackPlan(int $tenantId): ?ActivityPlan
    {
        $conversationId = $this->conversationContext?->get() ?? 0;

        if ($conversationId <= 0) {
            return null;
        }

        $task = AiTask::where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->where('type', 'activity_plan_draft')
            ->where('status', AiTask::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->first();

        $fallbackPlanId = (int) ($task->result['plan_id'] ?? 0);

        if ($fallbackPlanId <= 0) {
            return null;
        }

        return ActivityPlan::where('plan_id', $fallbackPlanId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * 兑底也找不到计划：附当前租户 planning 计划清单引导 LLM 自愈重试
     */
    private function planNotFoundError(int $planId, int $tenantId): array
    {
        $candidates = ActivityPlan::where('tenant_id', $tenantId)
            ->where('status', ActivityPlan::STATUS_PLANNING)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get()
            ->map(fn ($p) => [
                'plan_id' => (int) $p->plan_id,
                'title' => (string) ($p->plan_doc['title'] ?? '未命名'),
            ])->values()->toArray();

        return [
            'error' => true,
            'message' => ($planId > 0 ? "计划 [{$planId}] 不存在。" : '请提供 plan_id。')
                . 'plan_id 必须使用 activity_plan_draft 返回结果中的真实编号（长数字，不得自行编造）。'
                . ($candidates !== [] ? '当前可定稿的计划见 planning_plans 字段。' : ''),
            'planning_plans' => $candidates,
        ];
    }
}
