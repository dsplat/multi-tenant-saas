<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Dto;

/**
 * Headless Agent 执行结果 DTO
 *
 * HeadlessAgentService 的统一返回结构，封装无用户交互 ReAct 会话的产出。
 *
 * - text:         最终文本产出（LLM 结束语或中间积累的文本）
 * - toolCallsLog: 执行过的工具调用记录列表
 * - tokenUsage:   总 token 消耗
 * - partial:      是否为不完整执行（超 turns / 异常降级）
 * - error:        失败时的错误信息（partial=true 时可能有值）
 */
final class HeadlessResult
{
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCallsLog = [],
        public readonly array $tokenUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
        public readonly bool $partial = false,
        public readonly string $error = '',
    ) {}

    /**
     * 执行是否成功完成（非 partial）
     */
    public function isSuccess(): bool
    {
        return ! $this->partial;
    }
}
