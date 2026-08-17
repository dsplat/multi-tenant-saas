<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;

/**
 * 流式链路历史重建器（事实源 = DB）
 *
 * 职责：按 conversation_id 从 agent_conversation_messages 重建协议合法的
 * OpenAI messages 数组，供 ResolveController 下发给 Node 引擎——Node 流式
 * 链路的历史不再依赖前端上行文本（前端只传 user/assistant 纯文本，上一轮
 * 工具结果 plan_id/agent_id 到不了模型，是数值幻觉的结构根因）。
 *
 * 配对修复复用 AgentToolExecutor（与 PHP 非流式链路同一实现，杜绝两套口径）：
 * 缺 tool_call_id 回填、无响应 tool_call 剔除、孤儿 tool 降级为观察文本。
 *
 * 预算护栏（与 Node 现行口径一致）：最近 40 条、单条截断
 * （工具结果 2000、其余 20000）、头部裁齐到 user 轮边界只整轮丢弃。
 */
class StreamHistoryBuilder
{
    /** 历史轮次上限（与 Node sanitizeMessages 的 MAX_HISTORY_MESSAGES 同口径） */
    private const MAX_MESSAGES = 40;

    /** 单条 user/assistant 内容上限（与 Node MAX_CONTENT_LENGTH 同口径） */
    private const MAX_CONTENT_LENGTH = 20000;

    /** 工具结果内容上限（历史中仅需关键数值与结论，超长 JSON 无回放价值） */
    private const MAX_TOOL_CONTENT_LENGTH = 2000;

    /** 孤儿 tool 降级观察文本前缀（AgentToolExecutor::reconcileToolCallPairs 产物标记） */
    private const TOOL_OBSERVATION_PREFIX = '[工具执行结果]';

    public function __construct(
        private AgentToolExecutor $toolExecutor,
    ) {}

    /**
     * 重建会话历史为协议合法的 OpenAI messages 数组
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $tenantId  租户 ID（跨租户会话直接返回空，不泄露历史）
     * @return array messages 数组（不含 system——system_prompt 经 resolve 单独下发）
     */
    public function build(int $conversationId, int $tenantId): array
    {
        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return [];
        }

        // 取最近 N 条：created_at 是顺序的事实源（message_id 为无序随机全局 ID，
        // 不可作排序依据；MessageReportController 同批落库时逐条递增秒数保序）
        $messages = AgentConversationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'desc')
            ->limit(self::MAX_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $context = [];

        foreach ($messages as $msg) {
            $contextMsg = [
                'role' => $msg->role,
                'content' => $this->truncateContent((string) ($msg->content ?? ''), $msg->role),
            ];

            if ($msg->role === 'assistant' && $msg->tool_calls !== null) {
                $contextMsg['tool_calls'] = $this->toolExecutor->normalizeToolCalls((array) $msg->tool_calls, (int) $msg->message_id);
            }

            if ($msg->role === 'tool') {
                if ($msg->tool_call_id !== null) {
                    $contextMsg['tool_call_id'] = $msg->tool_call_id;
                }
                // 临时携带工具名供配对回填（reconcile 后移除，不会下发 LLM）
                $toolName = ($msg->metadata ?? [])['tool_name'] ?? '';
                if ($toolName !== '') {
                    $contextMsg['_tool_name'] = $toolName;
                }
            }

            $context[] = $contextMsg;
        }

        // 修复 assistant.tool_calls 与 tool 消息的配对关系（严格 OpenAI 协议要求成对）
        $context = $this->toolExecutor->reconcileToolCallPairs($context);

        return $this->alignToTurnBoundary($context);
    }

    /**
     * 单条内容截断：tool 结果用更严的上限，其余与 Node 口径一致
     */
    private function truncateContent(string $content, string $role): string
    {
        $limit = $role === 'tool' ? self::MAX_TOOL_CONTENT_LENGTH : self::MAX_CONTENT_LENGTH;

        return mb_strlen($content) > $limit ? mb_substr($content, 0, $limit) : $content;
    }

    /**
     * 头部裁齐到 user 轮边界：截断后首条若是 assistant/tool（或其降级观察文本），
     * 协议上属半轮历史，整轮丢弃（只丢头部残轮，尾部当前轮完整保留）
     */
    private function alignToTurnBoundary(array $context): array
    {
        foreach ($context as $i => $msg) {
            if (($msg['role'] ?? '') === 'user'
                && ! str_starts_with((string) ($msg['content'] ?? ''), self::TOOL_OBSERVATION_PREFIX)) {
                return $i === 0 ? $context : array_values(array_slice($context, $i));
            }
        }

        return [];
    }
}
