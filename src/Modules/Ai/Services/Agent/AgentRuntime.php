<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\AgentResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;

/**
 * Agent 运行时 — ReAct 循环编排器（非流式 + 流式）
 *
 * 职责：编排 ReAct 推理循环（run/runStream/continueWithToolResults），
 * 委托具体能力给三个协作者：
 * - AgentToolExecutor: 工具执行、风险分区、L2 确认
 * - AgentContextBuilder: 上下文组装、Prompt 解析、记忆注入/提取
 * - AgentChatClient: AI 推理调用、降级容错、模型配置
 *
 * 本类保留：循环控制、消息持久化、工作流链、记忆压缩触发。
 */
class AgentRuntime implements AgentRuntimeContract
{
    public function __construct(
        private AgentToolExecutor $toolExecutor,
        private AgentContextBuilder $contextBuilder,
        private AgentChatClient $chatClient,
        private ToolRegistryContract $toolRegistry,
        private AgentMonitorContract $monitor,
        private TenantContextContract $tenantContext,
        private ?WorkflowEngineContract $workflowEngine = null,
        private ?MemoryCompressor $memoryCompressor = null,
    ) {}

    /**
     * 执行 Agent（含工作流链）
     */
    public function execute(int $tenantId, int $agentId, array $input): AgentResponse
    {
        $this->tenantContext->storeTenantId((string) $tenantId);

        $agent = $this->loadAgent($agentId, $tenantId);

        if ($agent === null) {
            return AgentResponse::fromArray([
                'message' => '',
                'tool_calls' => [],
                'token_usage' => [],
                'finish_reason' => 'error',
                'error' => "Agent [{$agentId}] 不存在",
                'agent_id' => $agentId,
            ]);
        }

        $workflows = $this->resolveWorkflows($agentId);
        $workflowResults = [];
        $workflowFailed = false;

        if ($workflows->isNotEmpty()) {
            $workflowResults = $this->executeWorkflowChain($tenantId, $workflows, $input);
            $workflowFailed = $workflowResults !== []
                && $workflowResults[array_key_last($workflowResults)]['status'] === 'failed';
        }

        $message = $input['message'] ?? '';
        $conversationId = (int) ($input['conversation_id'] ?? 0);

        if ($conversationId > 0 && $message !== '') {
            $response = $this->run($agentId, $conversationId, $message, $input['options'] ?? []);
            if ($workflowResults !== []) {
                $raw = $response->raw;
                $raw['workflow_results'] = $workflowResults;
                $response = new AgentResponse(
                    message: $response->message,
                    toolCalls: $response->toolCalls,
                    tokenUsage: $response->tokenUsage,
                    finishReason: $response->finishReason,
                    agentId: $response->agentId,
                    conversationId: $response->conversationId,
                    model: $response->model,
                    error: $response->error,
                    raw: $raw,
                );
            }

            return $response;
        }

        if ($workflowFailed) {
            return AgentResponse::fromArray([
                'message' => '工作流执行失败',
                'tool_calls' => [],
                'token_usage' => [],
                'finish_reason' => 'error',
                'error' => '工作流链执行失败',
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'raw' => ['workflow_results' => $workflowResults],
            ]);
        }

        return AgentResponse::fromArray([
            'message' => $workflowResults !== [] ? '工作流执行完成' : '',
            'tool_calls' => [],
            'token_usage' => [],
            'finish_reason' => 'stop',
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
            'raw' => ['workflow_results' => $workflowResults],
        ]);
    }

    /**
     * 解析 Agent 关联的工作流
     */
    public function resolveWorkflows(int $agentId): Collection
    {
        $tenantId = $this->resolveTenantId();
        $agent = $this->loadAgent($agentId, $tenantId);

        if ($agent === null) {
            return collect();
        }

        return $agent->workflows()->get();
    }

    /**
     * 执行工作流链
     */
    public function executeWorkflowChain(int $tenantId, Collection $workflows, array $input): array
    {
        $results = [];
        $context = $input;

        foreach ($workflows as $workflow) {
            try {
                $execution = $this->workflowEngine->execute($workflow, $context);
            } catch (\Throwable $e) {
                Log::error('AgentRuntime: 工作流执行异常，中断工作流链', [
                    'workflow_id' => $workflow->workflow_id,
                    'tenant_id' => $tenantId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'workflow_id' => $workflow->workflow_id,
                    'execution_id' => null,
                    'status' => 'failed',
                    'context' => [],
                    'error' => $e->getMessage(),
                ];
                break;
            }

            $results[] = [
                'workflow_id' => $workflow->workflow_id,
                'execution_id' => $execution->execution_id,
                'status' => $execution->status,
                'context' => $execution->context ?? [],
            ];

            if ($execution->status !== 'completed') {
                Log::error('AgentRuntime: 工作流非正常结束，中断工作流链', [
                    'workflow_id' => $workflow->workflow_id,
                    'execution_id' => $execution->execution_id,
                    'tenant_id' => $tenantId,
                    'status' => $execution->status,
                ]);
                break;
            }

            $context = array_merge($context, $execution->context ?? []);
        }

        return $results;
    }

    /**
     * 执行 Agent 对话（ReAct 循环）
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

        $maxToolCalls = $options['max_tool_calls'] ?? $agent->effectiveMaxToolCalls();
        $interceptL2 = (bool) ($options['intercept_l2'] ?? false);
        $confirmTtl = isset($options['confirm_ttl']) ? (int) $options['confirm_ttl'] : null;

        // 自动触发记忆压缩
        $maxTokens = $options['max_tokens'] ?? ($agent->model_config['max_tokens'] ?? 8000);
        $this->compressMemory($conversationId, $maxTokens);

        // 保存用户消息
        $this->saveMessage($conversationId, 'user', $message);

        // 构建上下文
        $context = $this->contextBuilder->buildContext($agent, $conversationId, $message);

        // 构建 tools 定义
        $toolDefinitions = [];
        $effectiveTools = $this->chatClient->resolveEffectiveTools($agent, $options['exclude_tools'] ?? []);
        if (! empty($effectiveTools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($effectiveTools);
        }

        // ReAct 循环
        $allToolCalls = [];
        $loopCount = 0;
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        while ($loopCount < $maxToolCalls) {
            $loopCount++;

            $chatOptions = $this->chatClient->buildChatOptions($agent, $toolDefinitions, $options);
            $aiResponse = $this->chatClient->chatWithFallback($context, $chatOptions, $agent, $conversationId, $agentId);

            if ($aiResponse === null) {
                $errorMsg = 'AI 服务暂时不可用，请稍后重试。';
                $this->saveMessage($conversationId, 'assistant', $errorMsg);

                $this->monitor->logConversationTurn($conversationId, $agentId, [
                    'message' => $message,
                    'response' => $errorMsg,
                    'token_usage' => $totalUsage,
                    'tool_calls' => $allToolCalls,
                    'loop_count' => $loopCount,
                ]);

                return AgentResponse::fromArray([
                    'message' => $errorMsg,
                    'tool_calls' => $allToolCalls,
                    'token_usage' => $totalUsage,
                    'finish_reason' => 'error',
                    'error' => 'AI 服务异常：主驱动与 fallback 均失败',
                    'agent_id' => $agentId,
                    'conversation_id' => $conversationId,
                ]);
            }

            $totalUsage = $this->chatClient->accumulateUsage($totalUsage, $aiResponse->usage);

            // 无工具调用 → 文本回复
            if (! $aiResponse->hasToolCalls()) {
                $this->saveMessage($conversationId, 'assistant', $aiResponse->content, [
                    'model' => $aiResponse->model,
                ]);

                $this->contextBuilder->scheduleMemoryExtract($conversationId, $message, $aiResponse->content);

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

            // 有工具调用
            $allToolCalls = array_merge($allToolCalls, $aiResponse->toolCalls);

            $this->saveMessage($conversationId, 'assistant', $aiResponse->content, [
                'model' => $aiResponse->model,
            ], $aiResponse->toolCalls);

            $assistantMsg = ['role' => 'assistant', 'content' => $aiResponse->content];
            if (! empty($aiResponse->toolCalls)) {
                $assistantMsg['tool_calls'] = $aiResponse->toolCalls;
            }
            $context[] = $assistantMsg;

            // L2 风险工具拦截
            if ($interceptL2) {
                [$execCalls, $pendingCalls] = $this->toolExecutor->partitionByRisk($aiResponse->toolCalls);

                if ($pendingCalls !== []) {
                    foreach ($execCalls as $execCall) {
                        $this->toolExecutor->executeSingleToolCall($execCall, $conversationId, $agentId, $tenantId);
                    }

                    $pendingConfirmations = [];
                    foreach ($pendingCalls as $pendingCall) {
                        $pendingConfirmations[] = $this->toolExecutor->issuePendingConfirmation(
                            $pendingCall, $conversationId, $tenantId, $confirmTtl,
                        );
                    }

                    $this->monitor->logConversationTurn($conversationId, $agentId, [
                        'message' => $message,
                        'response' => $aiResponse->content,
                        'token_usage' => $totalUsage,
                        'tool_calls' => $aiResponse->toolCalls,
                        'loop_count' => $loopCount,
                        'pending_confirmation' => true,
                    ]);

                    return AgentResponse::fromArray([
                        'message' => $aiResponse->content,
                        'tool_calls' => $allToolCalls,
                        'token_usage' => $totalUsage,
                        'finish_reason' => 'pending_confirmation',
                        'pending_confirmations' => $pendingConfirmations,
                        'agent_id' => $agentId,
                        'conversation_id' => $conversationId,
                        'model' => $aiResponse->model,
                    ]);
                }
            }

            // 执行每个工具调用
            foreach ($aiResponse->toolCalls as $toolCall) {
                $allToolCalls[] = $toolCall;

                [$toolContextMsg] = $this->toolExecutor->executeSingleToolCall(
                    $toolCall, $conversationId, $agentId, $tenantId,
                );
                $context[] = $toolContextMsg;
            }
        }

        // 超过最大工具调用次数
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
     */
    public function continueWithToolResults(int $conversationId, array $toolResults, array $options = []): AgentResponse
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
            $metadata = ['tool_name' => $result['tool_name'] ?? ''];
            if (! empty($result['tool_call_id'])) {
                $metadata['tool_call_id'] = $result['tool_call_id'];
            }
            $this->saveMessage($conversationId, 'tool', $toolResult, $metadata);
        }

        // 构建上下文
        $context = $this->contextBuilder->getConversationContext($conversationId);

        // 构建 tools 定义
        $toolDefinitions = [];
        $effectiveTools = $this->chatClient->resolveEffectiveTools($agent, $options['exclude_tools'] ?? []);
        if (! empty($effectiveTools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($effectiveTools);
        }

        $chatOptions = $this->chatClient->buildChatOptions($agent, $toolDefinitions);
        $aiResponse = $this->chatClient->chatWithFallback($context, $chatOptions, $agent, $conversationId, $agentId);

        if ($aiResponse === null) {
            $errorMsg = 'AI 服务暂时不可用，请稍后重试。';
            $this->saveMessage($conversationId, 'assistant', $errorMsg);

            $this->monitor->logConversationTurn($conversationId, $agentId, [
                'message' => '',
                'response' => $errorMsg,
                'token_usage' => [],
                'tool_calls' => [],
            ]);

            return AgentResponse::fromArray([
                'message' => $errorMsg,
                'tool_calls' => [],
                'token_usage' => [],
                'finish_reason' => 'error',
                'error' => 'AI 服务异常：主驱动与 fallback 均失败',
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
            ]);
        }

        $this->saveMessage($conversationId, 'assistant', $aiResponse->content, [
            'model' => $aiResponse->model,
        ]);

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
     * 获取会话上下文（委托 AgentContextBuilder）
     */
    public function getConversationContext(int $conversationId, int $maxMessages = 20): array
    {
        return $this->contextBuilder->getConversationContext($conversationId, $maxMessages);
    }

    /**
     * 压缩会话记忆（摘要旧消息）
     */
    public function compressMemory(int $conversationId, int $maxTokens = 8000): bool
    {
        if ($this->memoryCompressor === null) {
            return false;
        }

        return $this->memoryCompressor->compress($conversationId, $maxTokens);
    }

    /**
     * 流式执行 Agent 对话 (SSE)
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

        $maxToolCalls = $options['max_tool_calls'] ?? $agent->effectiveMaxToolCalls();
        $maxTokens = $options['max_tokens'] ?? ($agent->model_config['max_tokens'] ?? 8000);

        $this->saveMessage($conversationId, 'user', $message);

        $context = $this->contextBuilder->buildContext($agent, $conversationId, $message);
        $toolDefinitions = [];
        $effectiveTools = $this->chatClient->resolveEffectiveTools($agent, $options['exclude_tools'] ?? []);
        if (! empty($effectiveTools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($effectiveTools);
        }

        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        try {
            return yield from $this->streamInner(
                $context, $agent, $agentId, $conversationId, $tenantId, $message,
                $toolDefinitions, $options, $maxToolCalls, 0, $totalUsage,
            );
        } finally {
            if ($this->memoryCompressor !== null) {
                app()->terminating(function () use ($conversationId, $maxTokens): void {
                    try {
                        $this->compressMemory($conversationId, $maxTokens);
                    } catch (\Throwable $e) {
                        Log::warning('AgentRuntime: 流后记忆压缩失败', [
                            'conversation_id' => $conversationId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }
        }
    }

    // ========== 内部方法 ==========

    /**
     * 流式推理递归核心
     *
     * @return Generator<int, StreamChunk, mixed, AgentResponse>
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
        $chatOptions = $this->chatClient->buildChatOptions($agent, $toolDefinitions, $options);
        $assistantContent = '';

        try {
            /** @var StreamChunk $chunk */
            foreach ($this->chatClient->streamChatWithFirstChunkFallback($context, $chatOptions, $agent, $conversationId, $agentId) as $chunk) {
                $assistantContent .= $chunk->text;

                yield $chunk;

                if ($chunk->hasToolCalls()) {
                    $this->saveMessage($conversationId, 'assistant', $assistantContent, [
                        'model' => '',
                    ], $chunk->toolCalls);

                    // L2 风险工具拦截
                    [$execCalls, $pendingCalls] = $this->toolExecutor->partitionByRisk($chunk->toolCalls);

                    if ($pendingCalls !== []) {
                        foreach ($execCalls as $execCall) {
                            $this->toolExecutor->executeSingleToolCall($execCall, $conversationId, $agentId, $tenantId);
                        }

                        foreach ($pendingCalls as $pendingCall) {
                            yield new StreamChunk(
                                pendingConfirmation: $this->toolExecutor->issuePendingConfirmation($pendingCall, $conversationId, $tenantId),
                            );
                        }

                        $this->monitor->logConversationTurn($conversationId, $agentId, [
                            'message' => $message,
                            'response' => $assistantContent,
                            'token_usage' => $totalUsage,
                            'tool_calls' => $chunk->toolCalls,
                            'loop_count' => $loopCount,
                            'pending_confirmation' => true,
                        ]);

                        yield new StreamChunk(finishReason: 'pending_confirmation');

                        return AgentResponse::fromArray([
                            'message' => $assistantContent,
                            'tool_calls' => $chunk->toolCalls,
                            'token_usage' => $totalUsage,
                            'finish_reason' => 'pending_confirmation',
                            'agent_id' => $agentId,
                            'conversation_id' => $conversationId,
                        ]);
                    }

                    // 执行工具并收集结果
                    [$context, $allToolCalls] = $this->toolExecutor->executeToolCalls(
                        $chunk->toolCalls, $context, $conversationId, $agentId, $tenantId, $assistantContent,
                    );

                    yield new StreamChunk(heartbeat: true);

                    $loopCount++;

                    if ($loopCount >= $maxToolCalls) {
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
        } catch (\Throwable $e) {
            Log::warning('AgentRuntime: 流式推理中断', [
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'partial_content' => mb_strimwidth($assistantContent, 0, 200, '...'),
            ]);

            if ($assistantContent !== '') {
                $this->saveMessage($conversationId, 'assistant', $assistantContent, [
                    'model' => '',
                ]);
            }

            $timeoutMsg = $assistantContent !== ''
                ? "\n\n[对话因超时或网络异常中断]"
                : 'AI 服务暂时不可用，请稍后重试。';

            yield new StreamChunk(
                text: $timeoutMsg,
                finishReason: 'error',
            );

            return AgentResponse::fromArray([
                'message' => $assistantContent . $timeoutMsg,
                'tool_calls' => [],
                'token_usage' => $totalUsage,
                'finish_reason' => 'error',
                'error' => $e->getMessage(),
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
            ]);
        }

        // 正常结束（无工具调用）
        $this->saveMessage($conversationId, 'assistant', $assistantContent, [
            'model' => '',
        ]);

        $this->contextBuilder->scheduleMemoryExtract($conversationId, $message, $assistantContent);

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

    private function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            throw new DomainException('无法从团队上下文解析 tenant_id');
        }

        return (int) $tenantId;
    }

    private function loadAgent(int $agentId, int $tenantId): ?Agent
    {
        return Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();
    }
}
