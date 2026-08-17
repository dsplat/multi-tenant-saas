<?php

namespace MultiTenantSaas\Modules\Ai\Services\AiTask;

use MultiTenantSaas\Modules\Ai\Models\AiTask;

/**
 * AI 长任务处理器契约
 *
 * 每种任务类型（ai_tasks.type）对应一个处理器类，由 queue worker
 * 在恢复租户上下文后调用。返回结果数组落库 ai_tasks.result；
 * 失败直接抛异常，由 ExecuteAiTaskJob 统一落库 failed + error。
 */
interface AiTaskHandlerContract
{
    /**
     * @return array<string, mixed> 任务结果（Node 轮询后注入 LLM 工具结果）
     *
     * @throws \Throwable 执行失败（任务标记 failed，error 取异常消息）
     */
    public function handle(AiTask $task): array;
}
