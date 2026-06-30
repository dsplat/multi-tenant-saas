<?php

namespace MultiTenantSaas\Contracts;

/**
 * Agent 监控契约
 *
 * 定义 Agent 运行时的日志记录、Token 用量统计、性能指标和成本估算接口。
 */
interface AgentMonitorContract
{
    /**
     * 记录会话轮次
     *
     * 记录 Agent 对话的每一轮（包括用户消息、AI 响应、工具调用等）。
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $agentId         Agent ID
     * @param  array  $data          轮次数据 {
     *                               message?: string,
     *                               response?: string,
     *                               tool_calls?: array,
     *                               token_usage?: array,
     *                               duration_ms?: int,
     *                               ...
     *                               }
     */
    public function logConversationTurn(int $conversationId, int $agentId, array $data): void;

    /**
     * 记录工具调用
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $agentId         Agent ID
     * @param  string  $toolName     工具名称
     * @param  array  $input         工具输入参数
     * @param  mixed  $output        工具输出结果
     * @param  int  $durationMs      执行耗时（毫秒）
     * @param  string|null  $error   错误信息（如果执行失败）
     */
    public function logToolCall(
        int $conversationId,
        int $agentId,
        string $toolName,
        array $input,
        mixed $output,
        int $durationMs,
        ?string $error = null
    ): void;

    /**
     * 获取 Token 用量统计
     *
     * @param  int  $agentId      Agent ID
     * @param  string  $startDate 开始日期（Y-m-d）
     * @param  string  $endDate   结束日期（Y-m-d）
     * @return array {
     *               prompt_tokens: int,
     *               completion_tokens: int,
     *               total_tokens: int,
     *               ...
     *               }
     */
    public function getTokenUsage(int $agentId, string $startDate, string $endDate): array;

    /**
     * 获取性能指标
     *
     * @param  int  $agentId      Agent ID
     * @param  string  $startDate 开始日期（Y-m-d）
     * @param  string  $endDate   结束日期（Y-m-d）
     * @return array {
     *               avg_response_time_ms: float,
     *               total_conversations: int,
     *               total_tool_calls: int,
     *               success_rate: float,
     *               ...
     *               }
     */
    public function getPerformanceMetrics(int $agentId, string $startDate, string $endDate): array;

    /**
     * 获取成本估算
     *
     * 根据 Token 用量和模型定价估算成本。
     *
     * @param  int  $agentId      Agent ID
     * @param  string  $startDate 开始日期（Y-m-d）
     * @param  string  $endDate   结束日期（Y-m-d）
     * @return float 预估成本（单位：元）
     */
    public function getCostEstimate(int $agentId, string $startDate, string $endDate): float;
}
