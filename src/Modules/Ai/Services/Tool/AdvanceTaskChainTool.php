<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Models\TaskChainRun;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRunner;

/**
 * advance_task_chain — 推进任务链一步（L1）
 *
 * run_id 缺省时取当前会话最新的未完成运行实例；
 * step_input 提交 input 步的用户输入；step_output 回填 L2 工具步经确认门
 * 执行后的结果；skip 跳过 optional 步。
 */
class AdvanceTaskChainTool implements ToolHandlerContract
{
    public function __construct(
        private readonly TaskChainRunner $runner,
        private readonly ToolConversationContext $conversationContext,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $runId = (int) ($arguments['run_id'] ?? 0);

        if ($runId <= 0) {
            $runId = $this->latestUnfinishedRunId($tenantId);
        }

        if ($runId <= 0) {
            return ['error' => true, 'message' => '当前会话没有进行中的任务链，可先调用 start_task_chain 启动'];
        }

        $stepInput = is_array($arguments['step_input'] ?? null) ? $arguments['step_input'] : [];
        $stepOutput = is_array($arguments['step_output'] ?? null) ? $arguments['step_output'] : [];

        return $this->runner->advance(
            $runId,
            $tenantId,
            $stepInput,
            $stepOutput,
            (bool) ($arguments['skip'] ?? false),
        );
    }

    /**
     * 当前会话最新的未完成运行实例 ID（无则 0）
     */
    private function latestUnfinishedRunId(int $tenantId): int
    {
        $conversationId = $this->conversationContext->get();

        if ($conversationId === null) {
            return 0;
        }

        return (int) TaskChainRun::where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->whereIn('status', TaskChainRun::UNFINISHED_STATUSES)
            ->orderByDesc('run_id')
            ->value('run_id');
    }
}
