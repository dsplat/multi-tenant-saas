<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRunner;

/**
 * start_task_chain — 启动一条预设任务链（L2，经确认卡片向用户展示链计划）
 *
 * 确认后创建 task_chain_runs 运行实例并给出第一步指引；
 * 后续经 advance_task_chain 逐步推进。
 */
class StartTaskChainTool implements ToolHandlerContract
{
    public function __construct(
        private readonly TaskChainRunner $runner,
        private readonly ToolConversationContext $conversationContext,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $chainKey = trim((string) ($arguments['chain_key'] ?? ''));

        if ($chainKey === '') {
            return ['error' => true, 'message' => '缺少 chain_key 参数，可先调用 list_task_chains 查看可用链'];
        }

        $conversationId = $this->conversationContext->get();

        if ($conversationId === null) {
            return ['error' => true, 'message' => '当前执行上下文缺少会话 ID，无法启动任务链'];
        }

        return $this->runner->start($chainKey, $tenantId, $conversationId);
    }
}
