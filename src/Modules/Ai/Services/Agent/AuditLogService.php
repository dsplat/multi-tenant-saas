<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AiAuditLog;

/**
 * AI 审计日志服务 — 统一记录与查询
 *
 * 写入策略：fire-and-forget，任何异常静默跳过（不阻断主链路）。
 * 查询策略：租户隔离 + 分页 + 按 action/agent/operator 过滤。
 *
 * 事件类型约定：
 * - conversation.start  — 会话开始
 * - message.send        — 用户发送消息
 * - tool.execute        — 工具执行
 * - agent.delegate      — 转派到其他 Agent
 * - agent.enable        — 启用 Agent
 * - agent.disable       — 停用 Agent
 * - prompt.update       — 提示词变更
 */
class AuditLogService
{
    public function __construct(
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * 写入审计事件
     *
     * fail-open：写入失败仅记 warning 日志，不抛异常。
     */
    public function log(
        string $action,
        ?string $summary = null,
        ?int $agentId = null,
        ?int $conversationId = null,
        ?int $operatorId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $detail = null,
        string $status = 'success',
    ): void {
        try {
            $tenantId = $this->tenantContext->resolveId();
            if ($tenantId === null) {
                return;
            }

            AiAuditLog::create([
                'tenant_id' => (int) $tenantId,
                'operator_id' => $operatorId,
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'summary' => $summary !== null ? mb_strimwidth($summary, 0, 500, '...') : null,
                'detail' => $detail,
                'status' => $status,
                'ip_address' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLogService: 写入失败（已跳过）', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 查询审计日志（租户隔离）
     *
     * @param  array  $filters  可选过滤：action, agent_id, operator_id, conversation_id, status, from, to
     * @param  int  $perPage  每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function query(array $filters = [], int $perPage = 20)
    {
        $query = AiAuditLog::orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (! empty($filters['operator_id'])) {
            $query->where('operator_id', $filters['operator_id']);
        }

        if (! empty($filters['conversation_id'])) {
            $query->where('conversation_id', $filters['conversation_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query->paginate($perPage);
    }

    // ---- 便捷方法 ----

    public function logToolExecution(int $agentId, int $conversationId, string $toolName, array $input, mixed $output, string $status = 'success'): void
    {
        $this->log(
            action: 'tool.execute',
            summary: "执行工具 {$toolName}",
            agentId: $agentId,
            conversationId: $conversationId,
            targetType: 'tool',
            targetId: $toolName,
            detail: ['input' => $input, 'output_summary' => mb_strimwidth(json_encode($output, JSON_UNESCAPED_UNICODE) ?: '', 0, 300, '...')],
            status: $status,
        );
    }

    public function logDelegation(int $fromAgentId, int $toAgentId, int $conversationId, string $reason = ''): void
    {
        $this->log(
            action: 'agent.delegate',
            summary: "转派到 Agent #{$toAgentId}" . ($reason !== '' ? "（{$reason}）" : ''),
            agentId: $fromAgentId,
            conversationId: $conversationId,
            targetType: 'agent',
            targetId: (string) $toAgentId,
            detail: ['from_agent' => $fromAgentId, 'to_agent' => $toAgentId, 'reason' => $reason],
        );
    }

    public function logAgentToggle(int $agentId, string $agentName, bool $enabled, ?int $operatorId = null): void
    {
        $this->log(
            action: $enabled ? 'agent.enable' : 'agent.disable',
            summary: ($enabled ? '启用' : '停用') . "数字员工「{$agentName}」",
            agentId: $agentId,
            operatorId: $operatorId,
            targetType: 'agent',
            targetId: (string) $agentId,
        );
    }
}
