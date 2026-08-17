<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Ai\Models\AiTask;

/**
 * AI 长任务状态查询（Node 引擎流内短连接轮询）
 *
 * 工具 execute 返回 {action:'await_task'} 后，Node 每数秒短连接轮询本端点
 * 直至任务终态；轮询发生在工具 execute 内部，LLM 与前端无感。
 *
 * abandoned=true 由 Node 在客户端断连（流中止）时上报：任务继续执行，
 * 完成时 ExecuteAiTaskJob 把结果兜底落库原会话。
 */
class TaskStatusController extends AiStreamingController
{
    /**
     * @OA\Post(
     *     path="/v1/ai-streaming/tasks/status",
     *     summary="查询 AI 长任务状态（Node 引擎轮询）",
     *     tags={"AI 流式网关"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"task_id"},
     *         @OA\Property(property="task_id", type="integer", description="ai_tasks.task_id"),
     *         @OA\Property(property="abandoned", type="boolean", description="客户端已断连放弃轮询（任务继续，结果兜底落库会话）")
     *     )),
     *
     *     @OA\Response(response=200, description="{status, result?, error?}"),
     *     @OA\Response(response=404, description="任务不存在或不属于当前团队")
     * )
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'abandoned' => ['sometimes', 'boolean'],
        ]);

        $this->resolveTenantId();

        // BelongsToTenant 全局作用域保证跨租户不可见（返回 404 不泄露存在性）
        $task = AiTask::where('task_id', (int) $data['task_id'])->first();

        if ($task === null) {
            abort(404, '任务不存在或不属于当前团队');
        }

        if (! empty($data['abandoned']) && ! ($task->metadata['abandoned'] ?? false)) {
            $task->update(['metadata' => array_merge((array) $task->metadata, ['abandoned' => true])]);
        }

        // 孤儿任务防御：worker 被 SIGKILL（如 queue timeout）时任务永卡非终态，
        // 无人推进；滞留超阈值的 pending/processing 落 failed 让轮询拿到终态
        $task->failIfOrphaned();

        return response()->json([
            'success' => true,
            'data' => array_filter([
                'task_id' => (int) $task->task_id,
                'status' => $task->status,
                'result' => $task->result,
                'error' => $task->error,
            ], fn ($v) => $v !== null),
        ]);
    }
}
