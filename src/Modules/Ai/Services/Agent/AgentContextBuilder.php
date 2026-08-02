<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;

/**
 * Agent 上下文构建器
 *
 * 职责：会话上下文组装（system_prompt + 历史 + 记忆注入 + 员工名册）、
 * Prompt 解析链、记忆提取调度。
 *
 * 从 AgentRuntime 提取，纯方法搬迁无逻辑变更。
 */
class AgentContextBuilder
{
    public function __construct(
        private AgentToolExecutor $toolExecutor,
        private TenantContextContract $tenantContext,
        private ?PromptService $promptService = null,
        private ?MemoryPipeline $memoryPipeline = null,
        private ?MemoryCompressor $memoryCompressor = null,
    ) {}

    /**
     * 获取会话上下文（历史消息 + 归一化 + 配对修复 + 截断）
     */
    public function getConversationContext(int $conversationId, int $maxMessages = 20): array
    {
        $tenantId = $this->resolveTenantId();

        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return [];
        }

        $agent = $conversation->agent;
        $context = [];

        // 系统提示词
        if ($agent !== null && ! empty($agent->system_prompt)) {
            $context[] = [
                'role' => 'system',
                'content' => $agent->system_prompt,
            ];
        }

        // 历史消息
        $messages = AgentConversationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->limit($maxMessages)
            ->get();

        foreach ($messages as $msg) {
            $contextMsg = [
                'role' => $msg->role,
                'content' => $msg->content ?? '',
            ];

            if ($msg->role === 'assistant' && $msg->tool_calls !== null) {
                $contextMsg['tool_calls'] = $this->toolExecutor->normalizeToolCalls((array) $msg->tool_calls, (int) $msg->id);
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

        // 应用截断策略（如果 MemoryCompressor 已注入）
        if ($this->memoryCompressor !== null) {
            $tokenBudget = 8000;
            if ($agent !== null) {
                $modelConfig = $agent->model_config ?? [];
                $tokenBudget = $modelConfig['max_tokens'] ?? 8000;
            }
            $context = $this->memoryCompressor->truncateContext($context, $tokenBudget);
        }

        return $context;
    }

    /**
     * 构建上下文消息列表（system_prompt + 历史 + 新消息）
     */
    public function buildContext(Agent $agent, int $conversationId, string $message): array
    {
        $context = $this->getConversationContext($conversationId);

        // 如果 getConversationContext 未包含 system_prompt，则补充
        $hasSystemPrompt = false;
        foreach ($context as $msg) {
            if ($msg['role'] === 'system') {
                $hasSystemPrompt = true;
                break;
            }
        }

        // Prompt 解析链：operator > tenant > system > agent.system_prompt
        $systemPrompt = $this->resolveSystemPrompt($agent, $conversationId);

        // 小秘书专属：注入租户已启用的数字员工名册（实时查询，开通/停用即时生效）
        if (($agent->role ?? '') === 'system_secretary') {
            $roster = $this->buildAgentsRoster($agent);
            if ($roster !== '') {
                $systemPrompt .= $roster;
            }
        }

        // 记忆注入：将实体高权重记忆追加到 system prompt 末尾
        if ($this->memoryPipeline !== null && $systemPrompt !== '') {
            $memoryBlock = $this->injectMemory($conversationId);
            if ($memoryBlock !== '') {
                $systemPrompt .= $memoryBlock;
            }
        }

        if (! $hasSystemPrompt && $systemPrompt !== '') {
            array_unshift($context, [
                'role' => 'system',
                'content' => $systemPrompt,
            ]);
        }

        // 新用户消息（如果尚未存在于上下文末尾）
        $lastMsg = end($context);
        if ($lastMsg === false || $lastMsg['role'] !== 'user' || $lastMsg['content'] !== $message) {
            $context[] = [
                'role' => 'user',
                'content' => $message,
            ];
        }

        return $context;
    }

    /**
     * 记忆提取后置钩子：对话结束后从用户消息中提取值得记住的信息
     *
     * 以 terminating 回调执行，不阻断响应返回。
     */
    public function scheduleMemoryExtract(int $conversationId, string $userMessage, string $assistantReply): void
    {
        if ($this->memoryPipeline === null) {
            return;
        }

        app()->terminating(function () use ($conversationId, $userMessage, $assistantReply): void {
            try {
                $conversation = AgentConversation::find($conversationId);
                if ($conversation === null) {
                    return;
                }

                $entityType = null;
                $entityId = null;

                if (! empty($conversation->staff_id)) {
                    $entityType = 'operator';
                    $entityId = (int) $conversation->staff_id;
                } elseif (! empty($conversation->customer_id)) {
                    $entityType = 'customer';
                    $entityId = (int) $conversation->customer_id;
                }

                if ($entityType !== null) {
                    $this->memoryPipeline->extract($entityType, $entityId, $userMessage, $assistantReply);
                }
            } catch (\Throwable $e) {
                Log::warning('AgentRuntime: 记忆提取失败（已跳过）', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    // ========== 内部方法 ==========

    /**
     * Prompt 解析链：PromptService(operator>tenant>system) → agent.effectiveSystemPrompt → 空
     *
     * fail-open：PromptService 异常时降级到模板优先的有效 prompt（与 Node 流式链路口径一致）。
     */
    private function resolveSystemPrompt(Agent $agent, int $conversationId): string
    {
        if ($this->promptService !== null) {
            $operatorId = $this->resolveOperatorId($conversationId);
            $role = $agent->role ?? '';

            if ($role !== '') {
                $resolved = $this->promptService->resolve($role, $operatorId, [
                    'agent_name' => $agent->name ?? '',
                    'operator_name' => $this->resolveOperatorName($operatorId),
                ]);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return $agent->effectiveSystemPrompt();
    }

    /**
     * 从会话解析 operator ID（staff_id）
     */
    private function resolveOperatorId(int $conversationId): ?int
    {
        try {
            $conversation = AgentConversation::find($conversationId);

            return $conversation?->staff_id ? (int) $conversation->staff_id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 解析 operator 名称（供变量插值）
     */
    private function resolveOperatorName(?int $operatorId): string
    {
        if ($operatorId === null) {
            return '';
        }

        try {
            return \DB::table('operators')->where('operator_id', $operatorId)->value('name') ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 构建租户已启用数字员工名册（小秘书专用）
     *
     * 实时查询，开通/停用即时反映。排除小秘书自身。
     */
    private function buildAgentsRoster(Agent $secretary): string
    {
        try {
            $agents = Agent::where('tenant_id', $secretary->tenant_id)
                ->where('enabled', true)
                ->where('agent_id', '!=', $secretary->agent_id)
                ->orderBy('agent_id')
                ->get(['agent_id', 'name', 'role', 'description']);

            if ($agents->isEmpty()) {
                return '';
            }

            $lines = $agents->map(fn ($a) => sprintf(
                '- **%s**（ID: %d，角色: %s）— %s',
                $a->name,
                $a->agent_id,
                $a->role ?? 'general',
                $a->description ?? '暂无描述',
            ))->toArray();

            return "\n\n## 当前团队已启用的数字员工\n"
                . "当用户的请求匹配以下员工的职责时，使用 delegate_to_agent 工具转派（传入对应 agent_id）：\n"
                . implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::warning('AgentRuntime: 构建员工名册失败（已跳过）', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * 记忆注入阶段：从会话解析实体 → 召回高权重记忆 → 格式化为文本块
     *
     * fail-open：任何异常返回空字符串，不阻断主链路。
     */
    private function injectMemory(int $conversationId): string
    {
        try {
            $conversation = AgentConversation::find($conversationId);
            if ($conversation === null) {
                return '';
            }

            // 确定实体：优先 staff_id（Operator），其次 customer_id
            $entityType = null;
            $entityId = null;

            if (! empty($conversation->staff_id)) {
                $entityType = 'operator';
                $entityId = (int) $conversation->staff_id;
            } elseif (! empty($conversation->customer_id)) {
                $entityType = 'customer';
                $entityId = (int) $conversation->customer_id;
            }

            if ($entityType === null) {
                return '';
            }

            return $this->memoryPipeline->inject($entityType, $entityId);
        } catch (\Throwable $e) {
            Log::warning('AgentRuntime: 记忆注入失败（已跳过）', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            throw new DomainException('无法从团队上下文解析 tenant_id');
        }

        return (int) $tenantId;
    }
}
