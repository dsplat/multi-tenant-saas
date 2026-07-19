<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentToolLog;

/**
 * Agent 监控服务 — 日志记录、Token 统计、性能指标、成本估算
 *
 * 只读查询强制 tenant 隔离（通过 agent_conversations.tenant_id 关联）。
 */
class AgentMonitor implements AgentMonitorContract
{
    public function __construct(
        private TenantContextContract $tenantContext
    ) {}

    /**
     * 记录会话轮次
     *
     * 更新 conversation 的 token_usage（累加）和 message_count。
     */
    public function logConversationTurn(int $conversationId, int $agentId, array $data): void
    {
        $tenantId = $this->resolveTenantId();

        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return;
        }

        $updates = [
            'message_count' => $conversation->message_count + 1,
        ];

        if (! empty($data['token_usage'])) {
            $current = $conversation->token_usage ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ];

            $updates['token_usage'] = [
                'prompt_tokens' => ($current['prompt_tokens'] ?? 0) + ($data['token_usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => ($current['completion_tokens'] ?? 0) + ($data['token_usage']['completion_tokens'] ?? 0),
                'total_tokens' => ($current['total_tokens'] ?? 0) + ($data['token_usage']['total_tokens'] ?? 0),
            ];
        }

        $conversation->update($updates);
    }

    /**
     * 记录工具调用
     *
     * 写入 agent_tool_logs 并累加 conversation 的 token_usage。
     */
    public function logToolCall(
        int $conversationId,
        int $agentId,
        string $toolName,
        array $input,
        mixed $output,
        int $durationMs,
        ?string $error = null
    ): void {
        AgentToolLog::create([
            'conversation_id' => $conversationId,
            'agent_id' => $agentId,
            'tool_name' => $toolName,
            'input' => $input,
            'output' => is_array($output) ? $output : ['result' => $output],
            'duration_ms' => $durationMs,
            'status' => $error === null ? 'success' : 'error',
            'error' => $error,
            'created_at' => now(),
        ]);
    }

    /**
     * 获取 Token 用量统计
     *
     * 从 conversation.token_usage 聚合指定时间范围内的总量。
     */
    public function getTokenUsage(int $agentId, string $startDate, string $endDate): array
    {
        $tenantId = $this->resolveTenantId();

        $conversations = AgentConversation::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereNotNull('token_usage')
            ->get(['token_usage']);

        $total = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        foreach ($conversations as $conversation) {
            $usage = $conversation->token_usage ?? [];
            $total['prompt_tokens'] += $usage['prompt_tokens'] ?? 0;
            $total['completion_tokens'] += $usage['completion_tokens'] ?? 0;
            $total['total_tokens'] += $usage['total_tokens'] ?? 0;
        }

        return $total;
    }

    /**
     * 获取性能指标
     */
    public function getPerformanceMetrics(int $agentId, string $startDate, string $endDate): array
    {
        $tenantId = $this->resolveTenantId();

        $start = $startDate . ' 00:00:00';
        $end = $endDate . ' 23:59:59';

        // 总会话数
        $totalConversations = AgentConversation::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // 工具调用统计（通过 conversation 关联过滤 tenant）
        $toolCallStats = AgentToolLog::whereIn(
            'conversation_id',
            fn ($query) => $query->select('conversation_id')
                ->from('agent_conversations')
                ->where('agent_id', $agentId)
                ->where('tenant_id', $tenantId)
        )
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw('AVG(duration_ms) as avg_duration')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count")
            ->first();

        $totalCalls = (int) ($toolCallStats->total_calls ?? 0);
        $successCount = (int) ($toolCallStats->success_count ?? 0);

        return [
            'avg_response_time_ms' => round((float) ($toolCallStats->avg_duration ?? 0), 2),
            'total_conversations' => $totalConversations,
            'total_tool_calls' => $totalCalls,
            'success_rate' => $totalCalls > 0 ? round($successCount / $totalCalls, 4) : 0.0,
        ];
    }

    /**
     * 获取成本估算
     */
    public function getCostEstimate(int $agentId, string $startDate, string $endDate): float
    {
        $tenantId = $this->resolveTenantId();

        $conversations = AgentConversation::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereNotNull('token_usage')
            ->get(['token_usage']);

        $totalCost = 0.0;
        $model = $this->resolveAgentModel($agentId);

        foreach ($conversations as $conversation) {
            $usage = $conversation->token_usage ?? [];
            $promptTokens = $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completion_tokens'] ?? 0;

            if ($promptTokens === 0 && $completionTokens === 0) {
                continue;
            }

            $totalCost += AiPricing::calculateCost($model, $promptTokens, $completionTokens);
        }

        return round($totalCost, 4);
    }

    /**
     * 解析当前团队 ID
     */
    private function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            throw new \RuntimeException('无法从团队上下文解析 tenant_id');
        }

        return (int) $tenantId;
    }

    /**
     * 解析 Agent 的首选模型
     */
    private function resolveAgentModel(int $agentId): string
    {
        $tenantId = $this->resolveTenantId();

        $agent = Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($agent === null) {
            return config('ai.default_model', 'gpt-4o-mini');
        }

        $modelConfig = $agent->model_config ?? [];

        return $modelConfig['preferred_model']
            ?? config('ai.default_model', 'gpt-4o-mini');
    }
}
