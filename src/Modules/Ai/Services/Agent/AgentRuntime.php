<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

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
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\AgentResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;

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
        private ?ActionConfirmService $actionConfirm = null,
        private ?MemoryPipeline $memoryPipeline = null,
        private ?PromptService $promptService = null,
    ) {}

    /**
     * 执行 Agent（含工作流链）
     *
     * 加载 Agent 配置 → 解析关联工作流 → 执行工作流链 → 处理对话。
     * 若 input 中包含 conversation_id 和 message，则委托 run() 执行对话。
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $agentId  Agent ID
     * @param  array  $input  输入数据 {
     *                        message?: string,
     *                        conversation_id?: int,
     *                        options?: array,
     *                        ...
     *                        }
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
     * @param  int  $tenantId  租户 ID
     * @param  Collection  $workflows  工作流集合
     * @param  array  $input  初始输入上下文
     * @return array 每个工作流的执行结果
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
     * @param  int  $agentId  Agent ID
     * @param  int  $conversationId  会话 ID
     * @param  string  $message  用户消息
     * @param  array  $options  可选配置 {
     *                          max_tool_calls?: int,
     *                          temperature?: float,
     *                          ...
     *                          }
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

        // 构建 tools 定义（合并模板工具，确保模板新增工具自动可用）
        $toolDefinitions = [];
        $effectiveTools = $this->resolveEffectiveTools($agent);
        if (! empty($effectiveTools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($effectiveTools);
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

                // 记忆提取后置钩子（terminating 回调，不阻断响应）
                $this->scheduleMemoryExtract($conversationId, $message, $aiResponse->content);

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
     * @param  array  $toolResults  工具执行结果列表
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
            $metadata = ['tool_name' => $result['tool_name'] ?? ''];
            if (! empty($result['tool_call_id'])) {
                $metadata['tool_call_id'] = $result['tool_call_id'];
            }
            $this->saveMessage($conversationId, 'tool', $toolResult, $metadata);
        }

        // 构建上下文
        $context = $this->getConversationContext($conversationId);

        // 构建 tools 定义（合并模板工具）
        $toolDefinitions = [];
        $effectiveTools = $this->resolveEffectiveTools($agent);
        if (! empty($effectiveTools)) {
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($effectiveTools);
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
     * @param  int  $maxMessages  最大历史消息数
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
     * @param  int  $maxTokens  token 阈值（默认 8000）
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
     * @param  int  $agentId  Agent ID
     * @param  int  $conversationId  会话 ID
     * @param  string  $message  用户消息
     * @param  array  $options  可选配置
     * @return Generator<int, StreamChunk, mixed, AgentResponse>
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
        $maxTokens = $options['max_tokens'] ?? ($agent->model_config['max_tokens'] ?? 8000);

        // 保存用户消息
        $this->saveMessage($conversationId, 'user', $message);

        // 构建上下文与工具定义（合并模板工具，确保模板新增工具自动可用）
        $context = $this->buildContext($agent, $conversationId, $message);
        $toolDefinitions = [];
        $effectiveTools = $this->resolveEffectiveTools($agent);
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
            // 记忆压缩移出流式关键路径：压缩含 LLM 调用（可达数十秒），
            // 同步执行会挡首帧（旧入口位置）或拖住 [DONE] 尾帧（finally 同步位置），
            // 改为 terminating 回调：响应完整送达后才执行，失败不影响已完成的对话
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

    /**
     * 流式推理递归核心
     *
     * 每次调用执行一轮 AI 推理 + 工具执行。若有工具调用，递归继续。
     *
     * @param  array  $context  当前消息上下文
     * @param  Agent  $agent  Agent 实例
     * @param  int  $agentId  Agent ID
     * @param  int  $conversationId  会话 ID
     * @param  int  $tenantId  租户 ID
     * @param  string  $message  原始用户消息（仅用于日志）
     * @param  array  $toolDefinitions  工具定义
     * @param  array  $options  调用选项
     * @param  int  $maxToolCalls  最大工具调用次数
     * @param  int  $loopCount  当前循环计数
     * @param  array  $totalUsage  累计 token 用量
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
        $chatOptions = $this->buildChatOptions($agent, $toolDefinitions, $options);

        // 累积 assistant 文本（Generator 局部变量在 yield 间保持状态）
        $assistantContent = '';

        try {
            // NOTE: 流式降级采用“起跑前换道”策略：首 chunk 产出前失败（连接/鉴权/限流等）
            // 可安全切换 fallback 驱动重新起流；首 chunk 之后的中断无法换道
            // （惰性序列已向前端吐字），仍由外层 catch 捕获为“流式中断”返回已生成内容。
            /** @var StreamChunk $chunk */
            foreach ($this->streamChatWithFirstChunkFallback($context, $chatOptions, $agent, $conversationId, $agentId) as $chunk) {
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

                    // L2 风险工具拦截：不执行，签发确认令牌后结束本轮，
                    // 由用户在前端确认卡片确认后经 confirm-action 端点执行
                    [$execCalls, $pendingCalls] = $this->partitionByRisk($chunk->toolCalls);

                    if ($pendingCalls !== []) {
                        // 同轮 L1 工具照常执行落库（确认后续答时上下文完整）
                        foreach ($execCalls as $execCall) {
                            $this->executeSingleToolCall($execCall, $conversationId, $agentId, $tenantId);
                        }

                        foreach ($pendingCalls as $pendingCall) {
                            yield new StreamChunk(
                                pendingConfirmation: $this->issuePendingConfirmation($pendingCall, $conversationId, $tenantId),
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

                    // 执行工具并收集结果（传入累积的 assistant 文本以保留上下文）
                    [$context, $allToolCalls] = $this->executeToolCalls(
                        $chunk->toolCalls, $context, $conversationId, $agentId, $tenantId, $assistantContent,
                    );

                    // 工具执行期间 SSE 无字节输出，nginx/FPM 会判死连接；
                    // 轮次边界产出心跳帧推送字节维持连接（控制器转为 SSE 注释行）
                    yield new StreamChunk(heartbeat: true);

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

        // 记忆提取后置钩子（terminating 回调，不阻断响应）
        $this->scheduleMemoryExtract($conversationId, $message, $assistantContent);

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
     * @param  array  $toolCalls  工具调用列表（OpenAI 格式）
     * @param  array  $context  当前消息上下文
     * @param  int  $conversationId  会话 ID
     * @param  int  $agentId  Agent ID
     * @param  int  $tenantId  租户 ID
     * @param  string  $assistantContent  助手累积文本（工具调用前的文本内容）
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
     * @param  array  $toolCall  单个工具调用（OpenAI 格式）
     * @param  int  $conversationId  会话 ID
     * @param  int  $agentId  Agent ID
     * @param  int  $tenantId  租户 ID
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
     * 按 risk 等级拆分工具调用：L1 直接执行 / L2 需确认
     *
     * @param  array  $toolCalls  工具调用列表（OpenAI 格式）
     * @return array{0: array, 1: array} [L1 执行列表, L2 待确认列表]
     */
    private function partitionByRisk(array $toolCalls): array
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
     * @return array 确认卡片载荷（token/args_hash/expires_in/tool_slug/tool_name/arguments/conversation_id）
     */
    private function issuePendingConfirmation(array $toolCall, int $conversationId, int $tenantId): array
    {
        $slug = $toolCall['function']['name'] ?? $toolCall['name'] ?? '';
        $arguments = $toolCall['function']['arguments'] ?? $toolCall['arguments'] ?? [];

        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true) ?? [];
        }

        $toolCallId = $toolCall['id'] ?? $toolCall['tool_call_id'] ?? null;
        $issued = $this->actionConfirm->issue($tenantId, $conversationId, $slug, $arguments, $toolCallId);

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
     * 从 TenantContextContract 解析当前团队 ID
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

        // Prompt 解析链：operator > tenant > system > agent.system_prompt
        $systemPrompt = $this->resolveSystemPrompt($agent, $conversationId);

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
     * Prompt 解析链：PromptService(operator>tenant>system) → agent.system_prompt → 空
     *
     * fail-open：PromptService 异常时降级到 agent.system_prompt。
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

        return $agent->system_prompt ?? '';
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

    /**
     * 记忆提取后置钩子：对话结束后从用户消息中提取值得记住的信息
     *
     * 以 terminating 回调执行，不阻断响应返回。
     */
    private function scheduleMemoryExtract(int $conversationId, string $userMessage, string $assistantReply): void
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

    /**
     * 构建 chat 调用选项
     */
    private function buildChatOptions(Agent $agent, array $toolDefinitions = [], array $overrides = []): array
    {
        $modelConfig = $this->resolveModelConfig($agent);

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
     * 解析 Agent 生效的模型配置
     *
     * 系统小秘书（role=system_secretary）强制走平台级 config('ai.secretary')：
     * 平台买单、不读租户维护的 model_config，也不进租户配额路径。
     */
    private function resolveModelConfig(Agent $agent): array
    {
        if ($agent->role === 'system_secretary' && config('ai.secretary.enabled', true)) {
            return [
                'preferred_provider' => config('ai.secretary.provider'),
                'preferred_model' => config('ai.secretary.model'),
                'fallback_provider' => config('ai.secretary.fallback_provider'),
                'fallback_model' => config('ai.secretary.fallback_model'),
                'temperature' => config('ai.secretary.temperature', 0.3),
                'max_tokens' => config('ai.secretary.max_tokens', 2000),
            ];
        }

        return $agent->model_config ?? [];
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
     * @param  array  $context  消息上下文
     * @param  array  $chatOptions  主驱动调用选项
     * @param  Agent  $agent  Agent 实例（读取 fallback 配置）
     * @param  int  $conversationId  会话 ID（用于日志）
     * @param  int  $agentId  Agent ID（用于日志）
     * @return AiResponse|null 成功返回响应，全部失败返回 null
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

        // 检查 fallback 配置（秘书走平台级配置）
        $modelConfig = $this->resolveModelConfig($agent);
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

    /**
     * 流式 AI 调用（含首 chunk 前降级）
     *
     * “起跑前换道”：显式驱动 Generator 至首 chunk，若在此之前失败
     *（连接拒绝/鉴权/限流等，尚未向前端吐任何字节）则切 fallback 驱动重新起流；
     * 首 chunk 之后的异常不在此处理，由 streamInner 外层 catch 收尾。
     *
     * @param  array  $context  消息上下文
     * @param  array  $chatOptions  主驱动调用选项
     * @param  Agent  $agent  Agent 实例（读取 fallback 配置）
     * @param  int  $conversationId  会话 ID（用于日志）
     * @param  int  $agentId  Agent ID（用于日志）
     * @return Generator<int, StreamChunk>
     */
    private function streamChatWithFirstChunkFallback(
        array $context,
        array $chatOptions,
        Agent $agent,
        int $conversationId,
        int $agentId,
    ): Generator {
        $stream = $this->aiService->streamChat($context, $chatOptions);

        try {
            // valid() 触发底层请求直至首 chunk 产出（或抛异常）
            $valid = $stream->valid();
        } catch (\Throwable $primaryError) {
            $fallbackOptions = $this->buildFallbackChatOptions($agent, $chatOptions);

            if ($fallbackOptions === null) {
                throw $primaryError;
            }

            Log::warning('AgentRuntime: 流式主驱动首 chunk 前失败，换道 fallback', [
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'provider' => $chatOptions['provider'] ?? 'unknown',
                'model' => $chatOptions['model'] ?? 'unknown',
                'fallback_provider' => $fallbackOptions['provider'] ?? 'unknown',
                'fallback_model' => $fallbackOptions['model'] ?? 'unknown',
                'error' => $primaryError->getMessage(),
            ]);

            $stream = $this->aiService->streamChat($context, $fallbackOptions);
            $valid = $stream->valid();
        }

        while ($valid) {
            yield $stream->current();
            $stream->next();
            $valid = $stream->valid();
        }
    }

    /**
     * 解析 Agent 的有效工具列表（DB 快照 ∪ 模板最新工具）
     *
     * Agent 创建时从模板 clone tools 到 DB，之后模板新增工具不会自动同步。
     * 此方法在运行时将模板工具合并进来（只增不减），确保模板迭代后已有 Agent 也能使用新工具。
     *
     * @return list<string>
     */
    private function resolveEffectiveTools(Agent $agent): array
    {
        $dbTools = $agent->tools ?? [];

        // 尝试按 role 匹配预置模板
        $template = BuiltinAgentTemplates::findByKey($agent->role ?? '');
        if ($template === null) {
            return $dbTools;
        }

        $templateTools = $template['tools'] ?? [];
        if (empty($templateTools)) {
            return $dbTools;
        }

        // 合并：DB 已有 + 模板新增（去重，保持顺序）
        return array_values(array_unique(array_merge($dbTools, $templateTools)));
    }

    /**
     * 构建流式 fallback 调用选项
     *
     * 无 fallback 配置、或 fallback 与主选项 provider+model 完全相同
     *（原地重试无意义）时返回 null。
     */
    private function buildFallbackChatOptions(Agent $agent, array $chatOptions): ?array
    {
        $modelConfig = $this->resolveModelConfig($agent);
        $fallbackProvider = $modelConfig['fallback_provider'] ?? null;
        $fallbackModel = $modelConfig['fallback_model'] ?? null;

        if ($fallbackProvider === null && $fallbackModel === null) {
            return null;
        }

        $options = $chatOptions;
        if ($fallbackProvider !== null) {
            $options['provider'] = $fallbackProvider;
        }
        if ($fallbackModel !== null) {
            $options['model'] = $fallbackModel;
        }

        if (($options['provider'] ?? null) === ($chatOptions['provider'] ?? null)
            && ($options['model'] ?? null) === ($chatOptions['model'] ?? null)) {
            return null;
        }

        return $options;
    }
}
