<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRegistry;
use MultiTenantSaas\Modules\Ai\Services\TaskChain\TaskChainRunner;

/**
 * list_task_chains — 列出可用的预设任务链 + 当前会话可续跑的运行实例
 *
 * 秘书发起任务链的前置工具：先看清有哪些链、是否有中断的链可续跑，
 * 再决定 start_task_chain 或 advance_task_chain。
 */
class ListTaskChainsTool implements ToolHandlerContract
{
    public function __construct(
        private readonly TaskChainRegistry $registry,
        private readonly TaskChainRunner $runner,
        private readonly ToolConversationContext $conversationContext,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $conversationId = $this->conversationContext->get();

        return [
            'chains' => $this->registry->catalog(),
            'unfinished_runs' => $conversationId !== null
                ? $this->runner->unfinishedRuns($tenantId, $conversationId)
                : [],
        ];
    }
}
