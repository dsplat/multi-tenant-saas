<?php

namespace MultiTenantSaas\Contracts;

use Generator;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\AgentResponse;

/**
 * Agent 运行时契约
 *
 * 定义 Agent 对话执行的核心接口，包括同步/流式执行、工具调用续传、
 * 会话上下文管理和记忆压缩。
 */
interface AgentRuntimeContract
{
    /**
     * 执行 Agent 对话
     *
     * @param  int  $agentId         Agent ID
     * @param  int  $conversationId  会话 ID
     * @param  string  $message      用户消息
     * @param  array  $options       可选配置 {
     *                               max_tool_calls?: int,
     *                               temperature?: float,
     *                               ...
     *                               }
     * @return AgentResponse {message, tool_calls, token_usage, finish_reason}
     */
    public function run(int $agentId, int $conversationId, string $message, array $options = []): AgentResponse;

    /**
     * 流式执行 Agent 对话 (SSE)
     *
     * @param  int  $agentId         Agent ID
     * @param  int  $conversationId  会话 ID
     * @param  string  $message      用户消息
     * @param  array  $options       可选配置
     * @return Generator 逐 token 或逐块产出流式数据
     */
    public function runStream(int $agentId, int $conversationId, string $message, array $options = []): Generator;

    /**
     * 继续执行（工具调用后）
     *
     * 当 Agent 调用工具后，将工具执行结果加入上下文并继续对话。
     *
     * @param  int  $conversationId  会话 ID
     * @param  array  $toolResults   工具执行结果列表
     * @return AgentResponse
     */
    public function continueWithToolResults(int $conversationId, array $toolResults): AgentResponse;

    /**
     * 获取会话上下文
     *
     * 构建用于 AI 推理的消息上下文，包括系统提示词和历史消息。
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $maxMessages     最大历史消息数
     * @return array OpenAI 消息格式 [{role, content, ...}, ...]
     */
    public function getConversationContext(int $conversationId, int $maxMessages = 20): array;

    /**
     * 压缩会话记忆（摘要旧消息）
     *
     * 当会话历史过长时，自动摘要旧消息以节省 Token。
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $maxTokens       token 阈值（默认 8000）
     * @return bool 是否执行了压缩
     */
    public function compressMemory(int $conversationId, int $maxTokens = 8000): bool;
}
