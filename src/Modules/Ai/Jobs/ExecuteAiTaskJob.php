<?php

namespace MultiTenantSaas\Modules\Ai\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Models\AiTask;
use MultiTenantSaas\Modules\Ai\Services\AiTask\AiTaskHandlerRegistry;

/**
 * AI 长任务执行 Job
 *
 * queue worker 中执行：恢复租户上下文 → 调 AiTaskHandlerRegistry 分发到
 * 类型处理器 → 结果/失败落库 ai_tasks。任务生命周期独立于前端连接：
 * 客户端断连（Node 上报 abandoned）不杀任务，完成时结果兜底落库原会话。
 *
 * tries=1：LLM 类任务重试即重复烧 token，失败直接落 failed 交由上层决策。
 * timeout=600：worker CLI 未配 --timeout 时 Laravel 默认 60s 会 SIGKILL 长任务，
 * 致任务永卡 processing；job 属性覆盖 CLI 默认，与 Node 轮询上限（10min）对齐。
 */
class ExecuteAiTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $taskId,
        public int $tenantId,
    ) {}

    public function handle(AiTaskHandlerRegistry $registry): void
    {
        // 恢复租户上下文，确保后续查询遵循租户隔离（参照 DispatchEventJob）
        TenantContext::setTenantId((string) $this->tenantId);

        $task = AiTask::find($this->taskId);

        // 幂等：任务不存在（跨租户）或已到终态（重复派发）直接跳过
        if ($task === null || $task->isTerminal()) {
            return;
        }

        $task->update([
            'status' => AiTask::STATUS_PROCESSING,
            'attempts' => $task->attempts + 1,
        ]);

        try {
            $result = $registry->handle($task);
            $task->update([
                'status' => AiTask::STATUS_COMPLETED,
                'result' => $result,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AiTask] 任务执行失败', [
                'task_id' => $task->task_id,
                'type' => $task->type,
                'error' => $e->getMessage(),
            ]);
            $task->update([
                'status' => AiTask::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
        }

        $this->persistFallbackMessage($task->refresh());
    }

    /**
     * 队列层失败（timeout 超限/worker 异常）：TimeoutExceededException 不经
     * handle 的 catch，任务会永卡 processing——在此落 failed 并触发断连兜底。
     */
    public function failed(\Throwable $e): void
    {
        TenantContext::setTenantId((string) $this->tenantId);

        $task = AiTask::find($this->taskId);

        if ($task === null || $task->isTerminal()) {
            return;
        }

        Log::error('[AiTask] 队列层执行失败', [
            'task_id' => $task->task_id,
            'type' => $task->type,
            'error' => $e->getMessage(),
        ]);

        $task->update([
            'status' => AiTask::STATUS_FAILED,
            'error' => mb_substr('后台任务执行超时，请重新发起（' . $e->getMessage() . '）', 0, 2000),
            'completed_at' => now(),
        ]);

        $this->persistFallbackMessage($task->refresh());
    }

    /**
     * 断连兜底：客户端已放弃轮询（Node 断连时上报 abandoned）时，
     * 把结果作为 assistant 消息落库原会话，用户刷新页面历史可见。
     * 正常在线路径不落库（LLM 续答经 messages/report 落库，避免重复）。
     */
    private function persistFallbackMessage(AiTask $task): void
    {
        $abandoned = (bool) ($task->metadata['abandoned'] ?? false);
        $conversationId = (int) ($task->conversation_id ?? 0);

        if (! $abandoned || $conversationId <= 0 || ! $task->isTerminal()) {
            return;
        }

        $summary = (string) ($task->result['summary'] ?? '');
        if ($summary === '') {
            $summary = $task->status === AiTask::STATUS_COMPLETED
                ? '后台任务已完成，可继续对话查看结果。'
                : '后台任务执行失败：' . (string) ($task->error ?? '未知错误');
        }

        try {
            AgentConversationMessage::create([
                'conversation_id' => $conversationId,
                'role' => 'assistant',
                'content' => $summary,
                'metadata' => [
                    'source' => 'ai_task',
                    'task_id' => (int) $task->task_id,
                    'task_type' => $task->type,
                    'task_status' => $task->status,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 兜底落库失败不影响任务本体终态（会话可能已被删除）
            Log::warning('[AiTask] 断连兜底消息落库失败', [
                'task_id' => $task->task_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
