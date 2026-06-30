<?php

namespace MultiTenantSaas\Services\Agent\Dto;

/**
 * Agent 对话响应 DTO
 *
 * 封装 Agent 运行时的单次对话响应，包括 AI 回复内容、工具调用请求、
 * Token 用量统计和结束原因。
 *
 * 字段说明：
 *  - message:         AI 助手的文本回复内容
 *  - toolCalls:       AI 请求调用的工具列表（OpenAI Function Calling 格式）
 *  - tokenUsage:      本次对话的 Token 用量统计
 *  - finishReason:    对话结束原因（stop / tool_calls / length / error / max_tool_calls 等）
 *  - agentId:         Agent ID
 *  - conversationId:  会话 ID
 *  - model:           实际使用的模型名称
 *  - error:           错误信息（finish_reason=error 时有值）
 *  - raw:             原始后端响应（调试 / 透传）
 */
final class AgentResponse
{
    public function __construct(
        public readonly string $message = '',
        public readonly array $toolCalls = [],
        public readonly array $tokenUsage = [],
        public readonly string $finishReason = '',
        public readonly int $agentId = 0,
        public readonly int $conversationId = 0,
        public readonly string $model = '',
        public readonly string $error = '',
        public readonly array $raw = [],
    ) {}

    /**
     * 从数组构造
     *
     * @param  array  $data  {
     *                       message?: string,
     *                       tool_calls?: array,
     *                       token_usage?: array,
     *                       finish_reason?: string,
     *                       agent_id?: int,
     *                       conversation_id?: int,
     *                       model?: string,
     *                       error?: string,
     *                       raw?: array
     *                       }
     */
    public static function fromArray(array $data): static
    {
        return new self(
            message: (string) ($data['message'] ?? ''),
            toolCalls: (array) ($data['tool_calls'] ?? []),
            tokenUsage: (array) ($data['token_usage'] ?? []),
            finishReason: (string) ($data['finish_reason'] ?? ''),
            agentId: (int) ($data['agent_id'] ?? 0),
            conversationId: (int) ($data['conversation_id'] ?? 0),
            model: (string) ($data['model'] ?? ''),
            error: (string) ($data['error'] ?? ''),
            raw: (array) ($data['raw'] ?? []),
        );
    }

    /**
     * 是否包含工具调用
     */
    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    /**
     * 是否为正常结束（无工具调用）
     */
    public function isComplete(): bool
    {
        return $this->finishReason === 'stop' && ! $this->hasToolCalls();
    }

    /**
     * 获取 Prompt Token 数
     */
    public function getPromptTokens(): int
    {
        return (int) ($this->tokenUsage['prompt_tokens'] ?? 0);
    }

    /**
     * 获取 Completion Token 数
     */
    public function getCompletionTokens(): int
    {
        return (int) ($this->tokenUsage['completion_tokens'] ?? 0);
    }

    /**
     * 获取总 Token 数
     */
    public function getTotalTokens(): int
    {
        return (int) ($this->tokenUsage['total_tokens'] ?? 0);
    }
}
