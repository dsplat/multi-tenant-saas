<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Agent;

use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Events\ToolCallFailed;
use MultiTenantSaas\Models\Agent;
use MultiTenantSaas\Models\AgentConversation;
use MultiTenantSaas\Models\AgentConversationMessage;
use MultiTenantSaas\Services\Agent\Dto\AgentResponse;
use MultiTenantSaas\Services\Ai\AiResponse;
use MultiTenantSaas\Services\Ai\StreamChunk;

/**
 * Agent 运行时 — ReAct 循环（非流式 + 流式）+ 记忆压缩 + 降级容错
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
 * 降级容错：AI 驱动异常时自动切换 model_config.fallback_provider 重试；
 * 工具执行失败将错误信息以 role=tool 返回给 AI 决策；流式中断返回已生成内容。
 */
class AgentRuntime implements AgentRuntimeContract
{
    public function __construct(
        private AiTextServiceContract $aiService,
        private ToolRegistryContract $toolRegistry,
        private AgentMonitorContract $monitor,
        private TenantContextContract $tenantContext,
        private ?WorkflowEngineContract $workflowEngine = null,
        private ?MemoryCompressor $memoryCompressor = null,
    ) {}

    /**
     * 执行 Agent（含工作流链）
     *
     * 加载 Agent 配置 → 解析关联工作流 → 执行工作流链 → 处理对话。
     * 若 input 中包含 conversation_id 和 message，则委托 run() 执行对话。
     *
     * @param  int    $tenantId  租户 ID
     * @param  int    $agentId   Agent ID
     * @param  array  $input     输入数据 {
     *                            message?: string,
     *                            conversation_id?: int,
     *                            options?: array,
     *                            ...
     *                            }
     * @return AgentResponse
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
     *
     * 通过 Agent 的 workflows() 关系获取已排序的工作流集合。
     * 内部加载 Agent 实例并验证租户隔离。
     *
     * @param  int  $agentId  Agent ID
     * @return Collection
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
     *
     * 按顺序执行工作流集合，每个工作流的输出上下文
     * 会合并到输入中传递给下一个工作流。
     * 任一工作流失败或非 completed 状态则中断链式执行。
     *
     * @param  int         $tenantId   租户 ID
     * @param  Collection  $workflows  工作流集合
     * @param  array       $input      初始输入上下文
     * @return array  每个工作流的执行结果
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

            // 调用 AI 推理（含降级容错）
            $chatOptions = $this->buildChatOptions($agent, $toolDefinitions, $options);
            $aiResponse = $this->chatWithFallback($context, $chatOptions, $agent, $conversationId, $agentId);

            // AI 调用完全失败（主驱动 + fallback 均失败）
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
                $allToolCalls[] = $toolCall;

                [$toolContextMsg] = $this->executeSingleToolCall(
                    $toolCall, $conversationId, $agentId, $tenantId,
                );
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
        $aiResponse = $this->chatWithFallback($context, $chatOptions, $agent, $conversationId, $agentId);

        // AI 调用完全失败
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

        try {
            // NOTE: 流式场景不使用 chatWithFallback 进行 provider 降级。
            // streamChat() 返回 Generator，惰性序列无法在中途切换底层驱动实现。
            // 流式 AI 驱动异常将被外层 try/catch 捕获为"流式中断"，返回已生成内容。
            // 若需流式降级，需在驱动层实现（超出当前 TASK-046 范围）。
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
        } catch (\Throwable $e) {
            // 流式中断（超时/网络错误/驱动异常）
            Log::warning('AgentRuntime: 流式推理中断', [
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'partial_content' => mb_strimwidth($assistantContent, 0, 200, '...'),
            ]);

            // 保存已累积的部分内容
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
     * 统一处理工具执行的完整生命周期，供 run() 和 executeToolCalls() 复用。
     *
     * @param  array  $toolCall        单个工具调用（OpenAI 格式）
     * @param  int    $conversationId  会话 ID
     * @param  int    $agentId         Agent ID
     * @param  int    $tenantId        租户 ID
     * @return array{0: array, 1: string|null} 工具上下文消息 + 错误信息（null 表示无错误）
     */
    private function executeSingleToolCall(
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

        try {
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

        return [$toolContextMsg, $toolError];
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

    /**
     * AI 调用（含降级容错）
     *
     * 先尝试主驱动，失败后检查 agent.model_config 中的 fallback_provider/fallback_model，
     * 若配置了 fallback 则切换重试。全部失败返回 null。
     *
     * @param  array       $context      消息上下文
     * @param  array       $chatOptions  主驱动调用选项
     * @param  Agent       $agent        Agent 实例（读取 fallback 配置）
     * @param  int         $conversationId  会话 ID（用于日志）
     * @param  int         $agentId      Agent ID（用于日志）
     * @return AiResponse|null  成功返回响应，全部失败返回 null
     */
    private function chatWithFallback(
        array $context,
        array $chatOptions,
        Agent $agent,
        int $conversationId,
        int $agentId,
    ): ?AiResponse {
        // 尝试主驱动
        try {
            return $this->aiService->chat($context, $chatOptions);
        } catch (\Throwable $primaryError) {
            Log::warning('AgentRuntime: 主驱动 AI 调用失败', [
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'provider' => $chatOptions['provider'] ?? 'unknown',
                'model' => $chatOptions['model'] ?? 'unknown',
                'error' => $primaryError->getMessage(),
            ]);
        }

        // 检查 fallback 配置
        $modelConfig = $agent->model_config ?? [];
        $fallbackProvider = $modelConfig['fallback_provider'] ?? null;
        $fallbackModel = $modelConfig['fallback_model'] ?? null;

        if ($fallbackProvider === null && $fallbackModel === null) {
            Log::warning('AgentRuntime: 无 fallback 配置，AI 调用完全失败', [
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
            ]);
            return null;
        }

        // 构建 fallback 选项
        $fallbackOptions = $chatOptions;
        if ($fallbackProvider !== null) {
            $fallbackOptions['provider'] = $fallbackProvider;
        }
        if ($fallbackModel !== null) {
            $fallbackOptions['model'] = $fallbackModel;
        }

        // 尝试 fallback 驱动
        try {
            Log::info('AgentRuntime: 切换 fallback 驱动', [
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'fallback_provider' => $fallbackOptions['provider'] ?? 'unknown',
                'fallback_model' => $fallbackOptions['model'] ?? 'unknown',
            ]);

            return $this->aiService->chat($context, $fallbackOptions);
        } catch (\Throwable $fallbackError) {
            Log::error('AgentRuntime: fallback 驱动也失败', [
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'fallback_provider' => $fallbackOptions['provider'] ?? 'unknown',
                'fallback_model' => $fallbackOptions['model'] ?? 'unknown',
                'error' => $fallbackError->getMessage(),
            ]);

            return null;
        }
    }
}
