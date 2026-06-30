<?php

namespace MultiTenantSaas\Services\Agent;

use Generator;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Models\Agent;
use MultiTenantSaas\Models\AgentConversation;
use MultiTenantSaas\Models\AgentConversationMessage;
use MultiTenantSaas\Services\Agent\Dto\AgentResponse;
use MultiTenantSaas\Services\Ai\StreamChunk;

/**
 * Agent 运行时 — ReAct 循环（非流式 + 流式）+ 记忆压缩
 *
 * 加载 Agent 配置 → 构建上下文（system_prompt+历史+新消息）→ 调用 AI 推理 →
 * 文本则返回 / tool_calls 则经 ToolRegistry 执行后追加结果 → 循环至 max_tool_calls。
 *
 * 非流式通过 run() 返回 AgentResponse；流式通过 runStream() 逐 chunk 产出 StreamChunk，
 * 遇 tool_calls 暂停流式 → 执行工具 → 结果入上下文 → 继续流式 → 末尾发送 [DONE]。
 *
 * 记忆压缩：run()/runStream() 入口自动触发 MemoryCompressor.compressMemory()，
 * getConversationContext() 应用 token 预算截断策略。
 *
 * 降级（归 TASK-046）不在本类实现。
 */
class AgentRuntime implements AgentRuntimeContract
{
    public function __construct(
        private AiTextServiceContract $aiService,
        private ToolRegistryContract $toolRegistry,
        private AgentMonitorContract $monitor,
        private TenantContextContract $tenantContext,
        private ?MemoryCompressor $memoryCompressor = null,
    ) {}

    /**
     * 执行 Agent 对话（ReAct 循环）
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
    public function run(int $agentId, int $conversationId, string $message, array $options = []): AgentResponse
    {
        $tenantId = $this->resolveTenantId();

        $agent = $this->loadAgent($agentId, $tenantId);

        if ($agent === null) {
            return AgentResponse::fromArray([
                'message' => '',
                'tool_calls' => [],
                'token_usage' => [],
                'finish_reason' => 'error',
                'error' => "Agent [{$agentId}] 不存在",
            ]);
        }

        $maxToolCalls = $options['max_tool_calls'] ?? ($agent->model_config['max_tool_calls'] ?? 5);

        // 自动触发记忆压缩（如果 MemoryCompressor 已注入）
        $maxTokens = $options['max_tokens'] ?? ($agent->model_config['max_tokens'] ?? 8000);
        $this->compressMemory($conversationId, $maxTokens);

        // 保存用户消息
        $this->saveMessage($conversationId, 'user', $message);

        // 构建上下文
        $context = $this->buildContext($agent, $conversationId, $message);

        // 构建 tools 定义
        $toolDefinitions = [];
        if (! empty($agent->tools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($agent->tools);
        }

        // ReAct 循环
        $allToolCalls = [];
        $loopCount = 0;
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        while ($loopCount < $maxToolCalls) {
            $loopCount++;

            // 调用 AI 推理
            $chatOptions = $this->buildChatOptions($agent, $toolDefinitions, $options);
            $aiResponse = $this->aiService->chat($context, $chatOptions);

            // 累加 token 用量
            $totalUsage = $this->accumulateUsage($totalUsage, $aiResponse->usage);

            // 无工具调用 → 文本回复，结束循环
            if (! $aiResponse->hasToolCalls()) {
                // 保存 assistant 消息
                $this->saveMessage($conversationId, 'assistant', $aiResponse->content, [
                    'model' => $aiResponse->model,
                ]);

                // 记录会话轮次
                $this->monitor->logConversationTurn($conversationId, $agentId, [
                    'message' => $message,
                    'response' => $aiResponse->content,
                    'token_usage' => $totalUsage,
                    'tool_calls' => [],
                    'loop_count' => $loopCount,
                ]);

                return AgentResponse::fromArray([
                    'message' => $aiResponse->content,
                    'tool_calls' => [],
                    'token_usage' => $totalUsage,
                    'finish_reason' => $aiResponse->finishReason ?: 'stop',
                    'agent_id' => $agentId,
                    'conversation_id' => $conversationId,
                    'model' => $aiResponse->model,
                    'raw' => $aiResponse->raw,
                ]);
            }

            // 有工具调用 → 执行工具
            $allToolCalls = array_merge($allToolCalls, $aiResponse->toolCalls);

            // 保存 assistant 消息（含 tool_calls）
            $this->saveMessage($conversationId, 'assistant', $aiResponse->content, [
                'model' => $aiResponse->model,
            ], $aiResponse->toolCalls);

            // 将 assistant 消息加入上下文
            $assistantMsg = ['role' => 'assistant', 'content' => $aiResponse->content];
            if (! empty($aiResponse->toolCalls)) {
                $assistantMsg['tool_calls'] = $aiResponse->toolCalls;
            }
            $context[] = $assistantMsg;

            // 执行每个工具调用
            foreach ($aiResponse->toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? $toolCall['name'] ?? '';
                $toolArguments = $toolCall['function']['arguments'] ?? $toolCall['arguments'] ?? [];

                if (is_string($toolArguments)) {
                    $toolArguments = json_decode($toolArguments, true) ?? [];
                }

                $startTime = microtime(true);
                $toolOutput = null;
                $toolError = null;

                try {
                    $toolOutput = $this->toolRegistry->execute($toolName, $toolArguments, $tenantId);
                } catch (\Throwable $e) {
                    $toolError = $e->getMessage();
                    Log::warning('AgentRuntime: 工具执行失败', [
                        'tool' => $toolName,
                        'agent_id' => $agentId,
                        'conversation_id' => $conversationId,
                        'error' => $toolError,
                    ]);
                }

                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                // 记录工具调用
                $this->monitor->logToolCall(
                    $conversationId,
                    $agentId,
                    $toolName,
                    $toolArguments,
                    $toolOutput,
                    $durationMs,
                    $toolError,
                );

                // 保存 tool 消息
                $toolResult = $toolError !== null
                    ? json_encode(['error' => $toolError])
                    : (is_string($toolOutput) ? $toolOutput : json_encode($toolOutput));

                $this->saveMessage($conversationId, 'tool', $toolResult, [
                    'tool_name' => $toolName,
                ]);

                // 将工具结果加入上下文
                $toolCallId = $toolCall['id'] ?? $toolCall['tool_call_id'] ?? null;
                $toolContextMsg = [
                    'role' => 'tool',
                    'content' => $toolResult,
                    'name' => $toolName,
                ];
                if ($toolCallId !== null) {
                    $toolContextMsg['tool_call_id'] = $toolCallId;
                }
                $context[] = $toolContextMsg;
            }
        }

        // 超过最大工具调用次数，强制结束
        $this->saveMessage($conversationId, 'assistant', '工具调用次数已达上限，对话自动结束。');

        $this->monitor->logConversationTurn($conversationId, $agentId, [
            'message' => $message,
            'response' => '工具调用次数已达上限',
            'token_usage' => $totalUsage,
            'tool_calls' => $allToolCalls,
            'loop_count' => $loopCount,
        ]);

        return AgentResponse::fromArray([
            'message' => '工具调用次数已达上限，对话自动结束。',
            'tool_calls' => $allToolCalls,
            'token_usage' => $totalUsage,
            'finish_reason' => 'max_tool_calls',
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * 继续执行（工具调用后）
     *
     * 将工具执行结果加入上下文并继续对话。
     *
     * @param  int  $conversationId  会话 ID
     * @param  array  $toolResults   工具执行结果列表
     * @return AgentResponse
     */
    public function continueWithToolResults(int $conversationId, array $toolResults): AgentResponse
    {
        $tenantId = $this->resolveTenantId();

        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return AgentResponse::fromArray([
                'message' => '',
                'tool_calls' => [],
                'token_usage' => [],
                'finish_reason' => 'error',
                'error' => "会话 [{$conversationId}] 不存在",
            ]);
        }

        $agentId = $conversation->agent_id;
        $agent = $this->loadAgent($agentId, $tenantId);

        if ($agent === null) {
            return AgentResponse::fromArray([
                'message' => '',
                'tool_calls' => [],
                'token_usage' => [],
                'finish_reason' => 'error',
                'error' => "Agent [{$agentId}] 不存在",
            ]);
        }

        // 保存工具结果消息
        foreach ($toolResults as $result) {
            $toolResult = $result['content'] ?? json_encode($result);
            $this->saveMessage($conversationId, 'tool', $toolResult, [
                'tool_name' => $result['tool_name'] ?? '',
            ]);
        }

        // 构建上下文
        $context = $this->getConversationContext($conversationId);

        // 构建 tools 定义
        $toolDefinitions = [];
        if (! empty($agent->tools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($agent->tools);
        }

        $chatOptions = $this->buildChatOptions($agent, $toolDefinitions);
        $aiResponse = $this->aiService->chat($context, $chatOptions);

        // 保存 assistant 消息
        $this->saveMessage($conversationId, 'assistant', $aiResponse->content, [
            'model' => $aiResponse->model,
        ]);

        // 记录会话轮次
        $this->monitor->logConversationTurn($conversationId, $agentId, [
            'message' => '',
            'response' => $aiResponse->content,
            'token_usage' => $aiResponse->usage,
            'tool_calls' => $aiResponse->toolCalls,
        ]);

        return AgentResponse::fromArray([
            'message' => $aiResponse->content,
            'tool_calls' => $aiResponse->toolCalls,
            'token_usage' => $aiResponse->usage,
            'finish_reason' => $aiResponse->finishReason ?: 'stop',
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
            'model' => $aiResponse->model,
            'raw' => $aiResponse->raw,
        ]);
    }

    /**
     * 获取会话上下文
     *
     * 构建用于 AI 推理的消息上下文，包括系统提示词和历史消息。
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $maxMessages     最大历史消息数
     * @return array OpenAI 消息格式 [{role, content, ...}, ...]
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
                $contextMsg['tool_calls'] = $msg->tool_calls;
            }

            if ($msg->role === 'tool' && $msg->tool_call_id !== null) {
                $contextMsg['tool_call_id'] = $msg->tool_call_id;
            }

            $context[] = $contextMsg;
        }

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
     * 压缩会话记忆（摘要旧消息）
     *
     * 当会话历史过长时，自动摘要旧消息以节省 Token。
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $maxTokens       token 阈值（默认 8000）
     * @return bool 是否执行了压缩
     */
    public function compressMemory(int $conversationId, int $maxTokens = 8000): bool
    {
        if ($this->memoryCompressor === null) {
            return false;
        }

        return $this->memoryCompressor->compressMemory($conversationId, $maxTokens);
    }

    /**
     * 流式执行 Agent 对话 (SSE)
     *
     * 基于 AiTextService.streamChat() 逐 chunk 产出 StreamChunk。
     * 遇 tool_calls 暂停流式 → 执行工具 → 结果入上下文 → 继续流式。
     * 末尾产出 finish_reason='stop' 的 StreamChunk（[DONE] 信号）。
     *
     * @param  int  $agentId         Agent ID
     * @param  int  $conversationId  会话 ID
     * @param  string  $message      用户消息
     * @param  array  $options       可选配置
     * @return \Generator<int, StreamChunk, mixed, AgentResponse>
     */
    public function runStream(int $agentId, int $conversationId, string $message, array $options = []): Generator
    {
        $tenantId = $this->resolveTenantId();
        $agent = $this->loadAgent($agentId, $tenantId);

        if ($agent === null) {
            yield new StreamChunk(text: "Agent [{$agentId}] 不存在", finishReason: 'error');
            return AgentResponse::fromArray([
                'message' => "Agent [{$agentId}] 不存在",
                'finish_reason' => 'error',
                'error' => "Agent [{$agentId}] 不存在",
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
            ]);
        }

        $maxToolCalls = $options['max_tool_calls'] ?? ($agent->model_config['max_tool_calls'] ?? 5);

        // 自动触发记忆压缩（如果 MemoryCompressor 已注入）
        $maxTokens = $options['max_tokens'] ?? ($agent->model_config['max_tokens'] ?? 8000);
        $this->compressMemory($conversationId, $maxTokens);

        // 保存用户消息
        $this->saveMessage($conversationId, 'user', $message);

        // 构建上下文与工具定义
        $context = $this->buildContext($agent, $conversationId, $message);
        $toolDefinitions = [];
        if (! empty($agent->tools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($agent->tools);
        }

        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        return yield from $this->streamInner(
            $context, $agent, $agentId, $conversationId, $tenantId, $message,
            $toolDefinitions, $options, $maxToolCalls, 0, $totalUsage,
        );
    }

    /**
     * 流式推理递归核心
     *
     * 每次调用执行一轮 AI 推理 + 工具执行。若有工具调用，递归继续。
     *
     * @param  array  $context          当前消息上下文
     * @param  Agent  $agent            Agent 实例
     * @param  int    $agentId          Agent ID
     * @param  int    $conversationId   会话 ID
     * @param  int    $tenantId         租户 ID
     * @param  string $message          原始用户消息（仅用于日志）
     * @param  array  $toolDefinitions  工具定义
     * @param  array  $options          调用选项
     * @param  int    $maxToolCalls     最大工具调用次数
     * @param  int    $loopCount        当前循环计数
     * @param  array  $totalUsage       累计 token 用量
     * @return \Generator<int, StreamChunk, mixed, AgentResponse>
     */
    private function streamInner(
        array $context,
        Agent $agent,
        int $agentId,
        int $conversationId,
        int $tenantId,
        string $message,
        array $toolDefinitions,
        array $options,
        int $maxToolCalls,
        int $loopCount,
        array $totalUsage,
    ): Generator {
        $chatOptions = $this->buildChatOptions($agent, $toolDefinitions, $options);

        // 累积 assistant 文本（Generator 局部变量在 yield 间保持状态）
        $assistantContent = '';

        /** @var StreamChunk $chunk */
        foreach ($this->aiService->streamChat($context, $chatOptions) as $chunk) {
            // 累积文本（在 yield 之前，确保状态更新）
            $assistantContent .= $chunk->text;

            // NOTE: 流式场景下 token 统计不可行——AiTextService.streamChat() 驱动层
            // 未从 SSE 结束块提取 usage 数据，StreamChunk.usage 始终为空数组。
            // $totalUsage 在当前架构下保持零值，属于已知限制。
            // 若要支持流式 token 统计，需修改 StreamChunk + 驱动层（超出 TASK-044 范围）。

            yield $chunk;

            // 有工具调用 → 暂停流式，执行工具后递归继续
            if ($chunk->hasToolCalls()) {
                // 保存 assistant 消息（含 tool_calls）
                $this->saveMessage($conversationId, 'assistant', $assistantContent, [
                    'model' => '',
                ], $chunk->toolCalls);

                // 执行工具并收集结果（传入累积的 assistant 文本以保留上下文）
                [$context, $allToolCalls] = $this->executeToolCalls(
                    $chunk->toolCalls, $context, $conversationId, $agentId, $tenantId, $assistantContent,
                );

                $loopCount++;

                if ($loopCount >= $maxToolCalls) {
                    // 超过最大工具调用次数
                    $this->saveMessage($conversationId, 'assistant', '工具调用次数已达上限，对话自动结束。');

                    $this->monitor->logConversationTurn($conversationId, $agentId, [
                        'message' => $message,
                        'response' => '工具调用次数已达上限',
                        'token_usage' => $totalUsage,
                        'tool_calls' => $allToolCalls,
                        'loop_count' => $loopCount,
                    ]);

                    yield new StreamChunk(
                        text: "\n\n[工具调用次数已达上限]",
                        finishReason: 'max_tool_calls',
                    );

                    return AgentResponse::fromArray([
                        'message' => '工具调用次数已达上限，对话自动结束。',
                        'tool_calls' => $allToolCalls,
                        'token_usage' => $totalUsage,
                        'finish_reason' => 'max_tool_calls',
                        'agent_id' => $agentId,
                        'conversation_id' => $conversationId,
                    ]);
                }

                // 递归继续流式
                return yield from $this->streamInner(
                    $context, $agent, $agentId, $conversationId, $tenantId, $message,
                    $toolDefinitions, $options, $maxToolCalls, $loopCount, $totalUsage,
                );
            }
        }

        // 正常结束（无工具调用）

        // 保存 assistant 消息
        $this->saveMessage($conversationId, 'assistant', $assistantContent, [
            'model' => '',
        ]);

        // 记录会话轮次
        $this->monitor->logConversationTurn($conversationId, $agentId, [
            'message' => $message,
            'response' => $assistantContent,
            'token_usage' => $totalUsage,
            'tool_calls' => [],
            'loop_count' => $loopCount,
        ]);

        return AgentResponse::fromArray([
            'message' => $assistantContent,
            'tool_calls' => [],
            'token_usage' => $totalUsage,
            'finish_reason' => 'stop',
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
            'model' => '',
        ]);
    }

    /**
     * 执行工具调用并返回更新后的上下文
     *
     * @param  array  $toolCalls        工具调用列表（OpenAI 格式）
     * @param  array  $context          当前消息上下文
     * @param  int    $conversationId   会话 ID
     * @param  int    $agentId          Agent ID
     * @param  int    $tenantId         租户 ID
     * @param  string $assistantContent 助手累积文本（工具调用前的文本内容）
     * @return array{0: array, 1: array} 更新后的上下文 + 工具调用列表
     */
    private function executeToolCalls(
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
            $toolName = $toolCall['function']['name'] ?? $toolCall['name'] ?? '';
            $toolArguments = $toolCall['function']['arguments'] ?? $toolCall['arguments'] ?? [];

            if (is_string($toolArguments)) {
                $toolArguments = json_decode($toolArguments, true) ?? [];
            }

            $startTime = microtime(true);
            $toolOutput = null;
            $toolError = null;

            try {
                $toolOutput = $this->toolRegistry->execute($toolName, $toolArguments, $tenantId);
            } catch (\Throwable $e) {
                $toolError = $e->getMessage();
                Log::warning('AgentRuntime: 工具执行失败', [
                    'tool' => $toolName,
                    'agent_id' => $agentId,
                    'conversation_id' => $conversationId,
                    'error' => $toolError,
                ]);
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

            $toolResult = $toolError !== null
                ? json_encode(['error' => $toolError])
                : (is_string($toolOutput) ? $toolOutput : json_encode($toolOutput));

            $this->saveMessage($conversationId, 'tool', $toolResult, [
                'tool_name' => $toolName,
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
            $context[] = $toolContextMsg;
        }

        return [$context, $allToolCalls];
    }

    /**
     * 累加 token 用量
     */
    private function accumulateUsage(array $total, array $usage): array
    {
        $total['prompt_tokens'] += $usage['prompt_tokens'] ?? 0;
        $total['completion_tokens'] += $usage['completion_tokens'] ?? 0;
        $total['total_tokens'] += $usage['total_tokens'] ?? 0;

        return $total;
    }

    /**
     * 从 TenantContextContract 解析当前租户 ID
     */
    private function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            throw new \RuntimeException('无法从租户上下文解析 tenant_id');
        }

        return (int) $tenantId;
    }

    /**
     * 加载 Agent（租户隔离）
     */
    private function loadAgent(int $agentId, int $tenantId): ?Agent
    {
        return Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * 构建上下文消息列表（system_prompt + 历史 + 新消息）
     */
    private function buildContext(Agent $agent, int $conversationId, string $message): array
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

        if (! $hasSystemPrompt && ! empty($agent->system_prompt)) {
            array_unshift($context, [
                'role' => 'system',
                'content' => $agent->system_prompt,
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
     * 构建 chat 调用选项
     */
    private function buildChatOptions(Agent $agent, array $toolDefinitions = [], array $overrides = []): array
    {
        $modelConfig = $agent->model_config ?? [];

        $options = [
            'model' => $modelConfig['preferred_model'] ?? config('ai.default_model', 'gpt-4o-mini'),
            'provider' => $modelConfig['preferred_provider'] ?? config('ai.default_provider', 'openai'),
            'temperature' => $modelConfig['temperature'] ?? 0.7,
            'max_tokens' => $modelConfig['max_tokens'] ?? 2000,
        ];

        if (! empty($toolDefinitions)) {
            $options['tools'] = $toolDefinitions;
            $options['tool_choice'] = 'auto';
        }

        return array_merge($options, $overrides);
    }

    /**
     * 保存消息到 agent_conversation_messages 表
     */
    private function saveMessage(
        int $conversationId,
        string $role,
        string $content,
        array $metadata = [],
        ?array $toolCalls = null,
    ): AgentConversationMessage {
        return AgentConversationMessage::create([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'tool_call_id' => $metadata['tool_call_id'] ?? null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
