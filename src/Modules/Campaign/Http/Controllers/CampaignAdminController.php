<?php

namespace MultiTenantSaas\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Services\CampaignTaskExecutor;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;

/**
 * Campaign 排期管理（租户管理端，权限 setting.update）
 *
 * 计划 CRUD + 编译 + 取消；任务批准/驳回/完成。
 * 范式对齐 IbotAdminController。
 */
class CampaignAdminController extends Controller
{
    public function __construct(
        private readonly PlanCompiler $compiler,
        private readonly CampaignTaskExecutor $executor,
    ) {}

    /**
     * 计划列表
     */
    public function index(): JsonResponse
    {
        $plans = CampaignPlan::withCount('tasks')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $plans]);
    }

    /**
     * 计划详情（含任务列表）
     */
    public function show(int $planId): JsonResponse
    {
        $plan = CampaignPlan::where('plan_id', $planId)->first();

        if (! $plan) {
            return response()->json(['success' => false, 'message' => '计划不存在'], 404);
        }

        $plan->load('tasks');

        return response()->json(['success' => true, 'data' => $plan]);
    }

    /**
     * 创建计划（planning 状态，plan_doc 校验）
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_doc' => 'required|array',
            'anchor_type' => 'nullable|string|max:50',
            'anchor_id' => 'nullable|integer',
            'playbook_key' => 'nullable|string|max:100',
        ]);

        // 编译前校验（schema/依赖/工具）
        $errors = $this->compiler->validate($validated['plan_doc']);
        if ($errors !== []) {
            return response()->json([
                'success' => false,
                'message' => '计划文档校验不通过',
                'errors' => $errors,
            ], 422);
        }

        $operator = $request->user();

        $plan = CampaignPlan::create([
            'tenant_id' => (int) TenantContext::getId(),
            'anchor_type' => $validated['anchor_type'] ?? null,
            'anchor_id' => $validated['anchor_id'] ?? null,
            'plan_doc' => $validated['plan_doc'],
            'status' => CampaignPlan::STATUS_PLANNING,
            'playbook_key' => $validated['playbook_key'] ?? null,
            'created_by' => (int) $operator->operator_id,
        ]);

        return response()->json(['success' => true, 'data' => $plan], 201);
    }

    /**
     * 编译计划（plan_doc → campaign_tasks，幂等）
     */
    public function compile(Request $request, int $planId): JsonResponse
    {
        $plan = CampaignPlan::where('plan_id', $planId)->first();

        if (! $plan) {
            return response()->json(['success' => false, 'message' => '计划不存在'], 404);
        }

        if (! $plan->isEditable()) {
            return response()->json(['success' => false, 'message' => '当前状态不允许编译'], 422);
        }

        $validated = $request->validate([
            'anchor_times' => 'required|array',
        ]);

        try {
            $this->compiler->compile($plan, $validated['anchor_times']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $plan->refresh()->load('tasks');

        return response()->json(['success' => true, 'data' => $plan]);
    }

    /**
     * 取消计划（未执行任务置 cancelled）
     */
    public function cancel(int $planId): JsonResponse
    {
        $plan = CampaignPlan::where('plan_id', $planId)->first();

        if (! $plan) {
            return response()->json(['success' => false, 'message' => '计划不存在'], 404);
        }

        if (in_array($plan->status, [CampaignPlan::STATUS_CLOSED, CampaignPlan::STATUS_CANCELLED], true)) {
            return response()->json(['success' => false, 'message' => '计划已终结'], 422);
        }

        // 非终态任务全部取消
        CampaignTask::where('plan_id', $plan->plan_id)
            ->whereNotIn('status', [
                CampaignTask::STATUS_DONE,
                CampaignTask::STATUS_FAILED,
                CampaignTask::STATUS_SKIPPED,
                CampaignTask::STATUS_CANCELLED,
            ])
            ->update(['status' => CampaignTask::STATUS_CANCELLED]);

        $plan->update(['status' => CampaignPlan::STATUS_CANCELLED]);

        return response()->json(['success' => true, 'data' => $plan->refresh()]);
    }

    /**
     * 批准任务（awaiting_confirm → 执行）
     */
    public function approveTask(int $taskId): JsonResponse
    {
        $task = CampaignTask::where('task_id', $taskId)->first();

        if (! $task) {
            return response()->json(['success' => false, 'message' => '任务不存在'], 404);
        }

        if ($task->status !== CampaignTask::STATUS_AWAITING_CONFIRM) {
            return response()->json(['success' => false, 'message' => '任务不在待确认状态'], 422);
        }

        $this->executor->execute($task);

        return response()->json(['success' => true, 'data' => $task->refresh()]);
    }

    /**
     * 驳回任务（awaiting_confirm → skipped）
     */
    public function rejectTask(int $taskId): JsonResponse
    {
        $task = CampaignTask::where('task_id', $taskId)->first();

        if (! $task) {
            return response()->json(['success' => false, 'message' => '任务不存在'], 404);
        }

        if ($task->status !== CampaignTask::STATUS_AWAITING_CONFIRM) {
            return response()->json(['success' => false, 'message' => '任务不在待确认状态'], 422);
        }

        $task->update(['status' => CampaignTask::STATUS_SKIPPED]);

        return response()->json(['success' => true, 'data' => $task->refresh()]);
    }

    /**
     * 人工完成任务（human 任务 running → done，可附 output）
     */
    public function completeTask(Request $request, int $taskId): JsonResponse
    {
        $task = CampaignTask::where('task_id', $taskId)->first();

        if (! $task) {
            return response()->json(['success' => false, 'message' => '任务不存在'], 404);
        }

        if ($task->status !== CampaignTask::STATUS_RUNNING) {
            return response()->json(['success' => false, 'message' => '任务不在执行中状态'], 422);
        }

        $validated = $request->validate([
            'output' => 'nullable|array',
        ]);

        $task->update([
            'status' => CampaignTask::STATUS_DONE,
            'output' => $validated['output'] ?? ['message' => '人工完成'],
        ]);

        return response()->json(['success' => true, 'data' => $task->refresh()]);
    }
}
