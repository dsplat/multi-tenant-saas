<?php

namespace MultiTenantSaas\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    // ==================== 活动日历（极简排期） ====================

    /**
     * 日历数据源：任务列表（可按活动 / 日期范围过滤）
     *
     * query: plan_id?（缺省=全部活动）、from?/to?（月范围）
     */
    public function tasksIndex(Request $request): JsonResponse
    {
        $query = CampaignTask::query()
            ->with('plan:plan_id,plan_doc')
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at');

        if ($request->filled('plan_id')) {
            $query->where('plan_id', (int) $request->query('plan_id'));
        }
        if ($request->filled('from')) {
            $query->where('scheduled_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('scheduled_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
        }

        $tasks = $query->get()->map(fn (CampaignTask $t) => [
            'task_id' => $t->task_id,
            'plan_id' => $t->plan_id,
            'plan_name' => $t->plan->plan_doc['title'] ?? ($t->plan->plan_doc['name'] ?? ''),
            'title' => $t->title,
            'scheduled_at' => $t->scheduled_at?->toDateTimeString(),
            'status' => $t->status,
            'remind' => $t->execution_mode === CampaignTask::MODE_REQUIRE_CONFIRM,
        ]);

        return response()->json(['success' => true, 'data' => $tasks]);
    }

    /**
     * 创建活动（manual 计划，status 直接 scheduled，无 DSL 校验）
     */
    public function storeManualPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $operator = $request->user();

        $plan = CampaignPlan::create([
            'tenant_id' => (int) TenantContext::getId(),
            'plan_doc' => [
                'schema' => 'campaign.plan/v1',
                'manual' => true,
                'title' => $validated['name'],
                'phases' => [],
            ],
            'status' => CampaignPlan::STATUS_SCHEDULED,
            'created_by' => (int) $operator->operator_id,
        ]);

        return response()->json(['success' => true, 'data' => $plan], 201);
    }

    /**
     * 加一件事（日历快速添加：标题 + 日期时间 + 提醒）
     */
    public function addTask(Request $request, int $planId): JsonResponse
    {
        $plan = CampaignPlan::where('plan_id', $planId)->first();

        if (! $plan) {
            return response()->json(['success' => false, 'message' => '活动不存在'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'scheduled_at' => 'required|date',
            'remind' => 'nullable|boolean',
        ]);

        $remind = (bool) ($validated['remind'] ?? false);

        $task = CampaignTask::create([
            'tenant_id' => (int) TenantContext::getId(),
            'plan_id' => $plan->plan_id,
            'task_key' => 'm_' . Str::lower(Str::random(12)),
            'title' => $validated['title'],
            'trigger_type' => CampaignTask::TRIGGER_AT_TIME,
            'scheduled_at' => Carbon::parse($validated['scheduled_at']),
            'assignee_type' => 'system',
            'action' => ['type' => 'human'],
            'execution_mode' => $remind ? CampaignTask::MODE_REQUIRE_CONFIRM : CampaignTask::MODE_AUTO,
            'depends_on' => [],
            'status' => CampaignTask::STATUS_PENDING,
        ]);

        return response()->json(['success' => true, 'data' => $task], 201);
    }

    /**
     * 编辑任务（改标题 / 改期 / 提醒开关 / 一键完成）
     */
    public function updateTask(Request $request, int $taskId): JsonResponse
    {
        $task = CampaignTask::where('task_id', $taskId)->first();

        if (! $task) {
            return response()->json(['success' => false, 'message' => '任务不存在'], 404);
        }

        if ($task->isTerminal()) {
            return response()->json(['success' => false, 'message' => '任务已终结，不可修改'], 422);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'scheduled_at' => 'nullable|date',
            'remind' => 'nullable|boolean',
            'status' => 'nullable|in:done,pending',
        ]);

        $attributes = [];

        if (array_key_exists('title', $validated) && $validated['title'] !== null) {
            $attributes['title'] = $validated['title'];
        }
        if (array_key_exists('scheduled_at', $validated) && $validated['scheduled_at'] !== null) {
            $attributes['scheduled_at'] = Carbon::parse($validated['scheduled_at']);
        }
        if (array_key_exists('remind', $validated) && $validated['remind'] !== null) {
            $attributes['execution_mode'] = $validated['remind']
                ? CampaignTask::MODE_REQUIRE_CONFIRM
                : CampaignTask::MODE_AUTO;
        }

        // 一键完成（todo 语义：从 pending/awaiting_confirm/running 直接置 done）
        if (($validated['status'] ?? null) === 'done') {
            $attributes['status'] = CampaignTask::STATUS_DONE;
            $attributes['output'] = ['message' => '手动完成'];
            $attributes['executed_at'] = Carbon::now();
        }

        if ($attributes !== []) {
            $task->update($attributes);
        }

        return response()->json(['success' => true, 'data' => $task->refresh()]);
    }

    /**
     * 删除任务
     */
    public function deleteTask(int $taskId): JsonResponse
    {
        $task = CampaignTask::where('task_id', $taskId)->first();

        if (! $task) {
            return response()->json(['success' => false, 'message' => '任务不存在'], 404);
        }

        $task->delete();

        return response()->json(['success' => true]);
    }

    /**
     * 删除活动（仅 manual 计划，连同其任务）
     */
    public function deletePlan(int $planId): JsonResponse
    {
        $plan = CampaignPlan::where('plan_id', $planId)->first();

        if (! $plan) {
            return response()->json(['success' => false, 'message' => '活动不存在'], 404);
        }

        if (! ($plan->plan_doc['manual'] ?? false)) {
            return response()->json(['success' => false, 'message' => '仅可删除手动创建的活动'], 422);
        }

        CampaignTask::where('plan_id', $plan->plan_id)->delete();
        $plan->delete();

        return response()->json(['success' => true]);
    }
}
