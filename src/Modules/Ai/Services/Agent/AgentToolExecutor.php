<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Events\ToolCallCompleted;
use MultiTenantSaas\Events\ToolCalled;
use MultiTenantSaas\Events\ToolCallFailed;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;

/**
 * Agent 工具执行器
 *
 * 职责：工具调用的完整生命周期——格式归一化、配对修复、风险分区、
 * L2 确认签发、单个/批量执行（含事件派发、监控日志、审计）。
 *
 * 从 AgentRuntime 提取，纯方法搬迁无逻辑变更。
 */
class AgentToolExecutor
{
    public function __construct(
        private ToolRegistryContract $toolRegistry,
        private AgentMonitorContract $monitor,
        private ?ActionConfirmService $actionConfirm = null,
    ) {}

    /**
     * 归一化 assistant.tool_calls 为 OpenAI 标准格式
     *
     * Node 流式引擎早期落库的是展示用平铺格式 {name, arguments}（无 function 嵌套），
     * 直接透传会被严格校验的 LLM API 拒绝（tool_calls.0.function Field required）。
     * 缺 id 时以消息 ID 合成确定性 id；arguments 统一为 JSON 字符串。
     *
     * @param  array  $toolCalls  落库的 tool_calls（标准或平铺格式）
     * @param  int  $messageId  消息 ID（合成确定性 id 用）
     * @return array OpenAI 标准 tool_calls
     */
    public function normalizeToolCalls(array $toolCalls, int $messageId): array
    {
        $normalized = [];

        foreach (array_values($toolCalls) as $i => $item) {
            if (! is_array($item)) {
                continue;
            }

            // 已是标准格式：补齐 id/type，arguments 统一为 JSON 字符串
            if (isset($item['function']['name'])) {
                $item['id'] = (string) ($item['id'] ?? "call_{$messageId}_{$i}");
                $item['type'] = $item['type'] ?? 'function';
                if (! is_string($item['function']['arguments'] ?? null)) {
                    $item['function']['arguments'] = json_encode($item['function']['arguments'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}';
                }
                $normalized[] = $item;

                continue;
            }

            // 平铺格式 {id?, name, arguments} → 标准格式
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $arguments = $item['arguments'] ?? [];
            $normalized[] = [
                'id' => (string) (($item['id'] ?? null) ?: "call_{$messageId}_{$i}"),
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => is_string($arguments) ? $arguments : (json_encode($arguments, JSON_UNESCAPED_UNICODE) ?: '{}'),
                ],
            ];
        }

        return $normalized;
    }

    /**
     * 配对修复 assistant.tool_calls 与后续 tool 消息
     *
     * 严格 OpenAI 协议要求每个 tool_call 均有配对的 tool 响应消息。历史中存在
     * Node 流内已解决但结果未落库的 tool_call（只报汇 assistant 消息）：
     * - 缺 tool_call_id 的 tool 消息按工具名/顺序回填 id
     * - 无响应的 tool_call 剔除（保留 assistant 文本）
     * - 无法配对的孤儿 tool 消息降级为 user 观察文本，信息不丢且协议合法
     *
     * @param  array  $context  归一化后的消息上下文（tool 消息可携临时 _tool_name）
     * @return array 配对修复后的上下文（已移除 _tool_name）
     */
    public function reconcileToolCallPairs(array $context): array
    {
        $result = [];
        $count = count($context);

        for ($i = 0; $i < $count; $i++) {
            $msg = $context[$i];
            $role = $msg['role'] ?? '';

            if ($role !== 'assistant' || empty($msg['tool_calls'])) {
                if ($role === 'tool') {
                    // 不在任何 assistant.tool_calls 组内的 tool 消息无论有无 id 均无法配对
                    // （assistant 分支已消费紧随的 tool 段），协议上必须降级保留信息
                    $msg = ['role' => 'user', 'content' => '[工具执行结果] ' . ($msg['content'] ?? '')];
                }
                unset($msg['_tool_name']);
                $result[] = $msg;

                continue;
            }

            // 收集紧随其后的 tool 消息段
            $toolMsgs = [];
            $j = $i + 1;
            while ($j < $count && ($context[$j]['role'] ?? '') === 'tool') {
                $toolMsgs[] = $context[$j];
                $j++;
            }

            $calls = array_values($msg['tool_calls']);
            $callIds = array_values(array_filter(array_map(fn ($call) => $call['id'] ?? '', $calls)));
            $matchedIds = [];

            // 已有 id 的 tool 消息先登记（仅限 id 确实存在于本轮 tool_calls）；
            // 缺 id 的优先按工具名其次按顺序回填
            foreach ($toolMsgs as &$toolMsg) {
                if (! empty($toolMsg['tool_call_id'])) {
                    if (in_array($toolMsg['tool_call_id'], $callIds, true)) {
                        $matchedIds[] = $toolMsg['tool_call_id'];
                    }

                    continue;
                }

                $assigned = null;
                $toolName = $toolMsg['_tool_name'] ?? '';
                foreach ($calls as $call) {
                    $callId = $call['id'] ?? '';
                    if ($callId === '' || in_array($callId, $matchedIds, true)) {
                        continue;
                    }
                    if ($toolName === '' || ($call['function']['name'] ?? '') === $toolName) {
                        $assigned = $callId;
                        break;
                    }
                }
                if ($assigned === null) {
                    foreach ($calls as $call) {
                        $callId = $call['id'] ?? '';
                        if ($callId !== '' && ! in_array($callId, $matchedIds, true)) {
                            $assigned = $callId;
                            break;
                        }
                    }
                }
                if ($assigned !== null) {
                    $toolMsg['tool_call_id'] = $assigned;
                    $matchedIds[] = $assigned;
                }
            }
            unset($toolMsg);

            // 剔除无响应的 tool_call（全部无响应则去掉 tool_calls 字段）
            $paired = array_values(array_filter(
                $calls,
                fn ($call) => in_array($call['id'] ?? '', $matchedIds, true),
            ));
            if ($paired !== []) {
                $msg['tool_calls'] = $paired;
            } else {
                unset($msg['tool_calls']);
            }
            unset($msg['_tool_name']);
            $result[] = $msg;

            foreach ($toolMsgs as $toolMsg) {
                if (! empty($toolMsg['tool_call_id']) && in_array($toolMsg['tool_call_id'], $matchedIds, true)) {
                    unset($toolMsg['_tool_name']);
                    $result[] = $toolMsg;
                } else {
                    $result[] = ['role' => 'user', 'content' => '[工具执行结果] ' . ($toolMsg['content'] ?? '')];
                }
            }

            $i = $j - 1;
        }

        return $result;
    }

    /**
     * 批量执行工具调用，返回更新后的上下文
     *
     * @return array{0: array, 1: array} [更新后的上下文, 所有工具调用列表]
     */
    public function executeToolCalls(
        array $toolCalls,
        array $context,
        int $conversationId,
        int $agentId,
        int $tenantId,
        string $assistantContent = '',
    ): array {
        $allToolCalls = [];

        // 将 assistant 消息加入上下文（消息已由 streamInner 保存）
        $context[] = ['role' => 'assistant', 'content' => $assistantContent, 'tool_calls' => $toolCalls];

        foreach ($toolCalls as $toolCall) {
            $allToolCalls[] = $toolCall;

            [$toolContextMsg, $toolError] = $this->executeSingleToolCall(
                $toolCall, $conversationId, $agentId, $tenantId,
            );
            $context[] = $toolContextMsg;
        }

        return [$context, $allToolCalls];
    }

    /**
     * 执行单个工具调用（含错误处理、日志、事件派发、消息保存）
     *
     * @param  array  $toolCall  单个工具调用（OpenAI 格式）
     * @param  int  $conversationId  会话 ID
     * @param  int  $agentId  Agent ID
     * @param  int  $tenantId  租户 ID
     * @return array{0: array, 1: string|null} 工具上下文消息 + 错误信息（null 表示无错误）
     */
    public function executeSingleToolCall(
        array $toolCall,
        int $conversationId,
        int $agentId,
        int $tenantId,
    ): array {
        $toolName = $toolCall['function']['name'] ?? $toolCall['name'] ?? '';
        $toolArguments = $toolCall['function']['arguments'] ?? $toolCall['arguments'] ?? [];

        if (is_string($toolArguments)) {
            $toolArguments = json_decode($toolArguments, true) ?? [];
        }

        $startTime = microtime(true);
        $toolOutput = null;
        $toolError = null;

        ToolCalled::dispatch($tenantId, $agentId, $conversationId, $toolName);

        try {
            // 会话感知工具（如任务链三工具）需要当前会话 ID，执行前注入
            app(ToolConversationContext::class)->set($conversationId);
            $toolOutput = $this->toolRegistry->execute($toolName, $toolArguments, $tenantId);

            // ToolRegistry 返回结构化错误（处理器运行时异常已封装）
            if (is_array($toolOutput) && ($toolOutput['error'] ?? false)) {
                $toolError = $toolOutput['message'] ?? '工具执行失败';
                $toolOutput = null;
            }
        } catch (\Throwable $e) {
            // 基础设施错误（工具未注册/类不存在）
            $toolError = $e->getMessage();
        }

        if ($toolError !== null) {
            Log::warning('AgentRuntime: 工具执行失败', [
                'tool' => $toolName,
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'error' => $toolError,
            ]);

            ToolCallFailed::dispatch($tenantId, $agentId, $conversationId, $toolName, $toolError, $toolArguments);
        } else {
            ToolCallCompleted::dispatch($tenantId, $agentId, $conversationId, $toolName);
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->monitor->logToolCall(
            $conversationId,
            $agentId,
            $toolName,
            $toolArguments,
            $toolOutput,
            $durationMs,
            $toolError,
        );

        // 审计日志：工具执行事件
        if (app()->bound(AuditLogService::class)) {
            app(AuditLogService::class)->logToolExecution(
                $agentId, $conversationId, $toolName, $toolArguments,
                $toolOutput ?? ['error' => $toolError],
                $toolError !== null ? 'failed' : 'success',
            );
        }

        $toolResult = $toolError !== null
            ? json_encode(['error' => $toolError])
            : (is_string($toolOutput) ? $toolOutput : json_encode($toolOutput));

        AgentConversationMessage::create([
            'conversation_id' => $conversationId,
            'role' => 'tool',
            'content' => $toolResult,
            'tool_calls' => null,
            'tool_call_id' => null,
            'metadata' => ['tool_name' => $toolName],
            'created_at' => now(),
        ]);

        $toolContextMsg = [
            'role' => 'tool',
            'content' => $toolResult,
            'name' => $toolName,
        ];
        $toolCallId = $toolCall['id'] ?? $toolCall['tool_call_id'] ?? null;
        if ($toolCallId !== null) {
            $toolContextMsg['tool_call_id'] = $toolCallId;
        }

        return [$toolContextMsg, $toolError];
    }

    /**
     * 按 risk 等级拆分工具调用：L1 直接执行 / L2 需确认
     *
     * @param  array  $toolCalls  工具调用列表（OpenAI 格式）
     * @return array{0: array, 1: array} [L1 执行列表, L2 待确认列表]
     */
    public function partitionByRisk(array $toolCalls): array
    {
        // 未注入 ActionConfirmService 时退化为全部直接执行（向后兼容）
        if ($this->actionConfirm === null) {
            return [$toolCalls, []];
        }

        $execCalls = [];
        $pendingCalls = [];

        foreach ($toolCalls as $toolCall) {
            $slug = $toolCall['function']['name'] ?? $toolCall['name'] ?? '';
            $tool = $slug !== '' ? $this->toolRegistry->get($slug) : null;

            if ($tool !== null && $tool->requiresConfirmation()) {
                $pendingCalls[] = $toolCall;
            } else {
                $execCalls[] = $toolCall;
            }
        }

        return [$execCalls, $pendingCalls];
    }

    /**
     * 为单个 L2 工具调用签发确认令牌，返回前端确认卡片载荷
     *
     * @param  array  $toolCall  L2 工具调用（OpenAI 格式）
     * @param  int|null  $ttlSeconds  自定义令牌有效期（IM 文本确认场景放宽），null 用默认
     * @return array 确认卡片载荷
     */
    public function issuePendingConfirmation(array $toolCall, int $conversationId, int $tenantId, ?int $ttlSeconds = null): array
    {
        $slug = $toolCall['function']['name'] ?? $toolCall['name'] ?? '';
        $arguments = $toolCall['function']['arguments'] ?? $toolCall['arguments'] ?? [];

        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true) ?? [];
        }

        $toolCallId = $toolCall['id'] ?? $toolCall['tool_call_id'] ?? null;
        $issued = $this->actionConfirm->issue($tenantId, $conversationId, $slug, $arguments, $toolCallId, $ttlSeconds);

        $tool = $this->toolRegistry->get($slug);

        return [
            'token' => $issued['token'],
            'args_hash' => $issued['args_hash'],
            'expires_in' => $issued['expires_in'],
            'tool_slug' => $slug,
            'tool_name' => $tool?->name ?? $slug,
            'arguments' => $arguments,
            'conversation_id' => $conversationId,
        ];
    }
}
