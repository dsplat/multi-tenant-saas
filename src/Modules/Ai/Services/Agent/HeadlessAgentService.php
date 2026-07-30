<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\HeadlessResult;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;

/**
 * Headless Agent Execution — 无用户交互的短 ReAct 会话
 *
 * 供 task-chain delegate 步和 campaign agent_react 任务共享使用。
 * 派给指定 agent role 执行若干轮工具调用后返回文本产出。
 *
 * 安全铁律：
 * - 仅注入 L1 工具（L2 不注入，LLM 看不到也不会调用）
 * - 额外过滤 delegate_to_agent / start_task_chain / advance_task_chain（headless 中无意义/危险）
 * - fail-open：所有 LLM/工具异常 try/catch，绝不向上抛异常
 *
 * AI 服务依赖注入规范：构造器注入，禁止 Facade/静态代理。
 */
class HeadlessAgentService
{
    /** 在 headless 模式下禁止注入的工具（虽是 L1 但无意义或有循环风险） */
    private const BLACKLISTED_TOOLS = [
        'delegate_to_agent',
        'start_task_chain',
        'advance_task_chain',
    ];

    public function __construct(
        private readonly ToolRegistryContract $toolRegistry,
        private readonly AiTextServiceContract $aiTextService,
        private readonly ToolConversationContext $conversationContext,
    ) {}

    /**
     * 启动一次无用户交互的 agent 执行
     *
     * @param  string  $agentRole  agent 角色标识（如 scrm_marketing）
     * @param  string  $prompt  用户输入 / 任务描述
     * @param  int  $tenantId  租户 ID
     * @param  int  $maxTurns  最大工具调用轮次（每轮可含多个工具调用）
     */
    public function execute(string $agentRole, string $prompt, int $tenantId, int $maxTurns = 3): HeadlessResult
    {
        $toolCallsLog = [];
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        try {
            // 1. 解析 agent 模板
            $template = $this->resolveTemplate($agentRole);

            if ($template === null) {
                return new HeadlessResult(
                    text: '',
                    partial: true,
                    error: "Agent role [{$agentRole}] 模板不存在",
                );
            }

            // 2. 构建工具集（仅 L1 + 排除黑名单）
            $toolSlugs = $this->resolveToolSlugs($template);
            $toolDefinitions = $this->toolRegistry->getToolDefinitions($toolSlugs);

            // 3. 构建初始消息
            $systemPrompt = (string) ($template['system_prompt'] ?? '');
            $messages = [];

            if ($systemPrompt !== '') {
                $messages[] = ['role' => 'system', 'content' => $systemPrompt];
            }

            $messages[] = ['role' => 'user', 'content' => $prompt];

            // 4. 构建 chat options
            $modelConfig = (array) ($template['model_config'] ?? BuiltinAgentTemplates::defaultModelConfig());
            $chatOptions = [
                'provider' => $modelConfig['preferred_provider'] ?? config('ai.default_provider', 'openai'),
                'model' => $modelConfig['preferred_model'] ?? config('ai.default_model', 'gpt-4o-mini'),
                'temperature' => $modelConfig['temperature'] ?? 0.3,
                'max_tokens' => $modelConfig['max_tokens'] ?? 2000,
            ];

            if ($toolDefinitions !== []) {
                $chatOptions['tools'] = $toolDefinitions;
            }

            // 5. 设置 ToolConversationContext（合成 ID，仅供工具内部查询，不存 DB）
            $syntheticConversationId = $tenantId * 100000 + random_int(1, 99999);
            $this->conversationContext->set($syntheticConversationId);

            // 6. ReAct 循环
            $text = '';

            for ($turn = 0; $turn < $maxTurns; $turn++) {
                $response = $this->aiTextService->chat($messages, $chatOptions);

                // 累计 token
                $this->accumulateUsage($totalUsage, $response->usage);

                // 无 tool_calls → 结束
                if (! $response->hasToolCalls()) {
                    $text = $response->content;
                    break;
                }

                // 有 tool_calls → 执行工具
                $assistantMessage = ['role' => 'assistant', 'content' => $response->content ?: null];

                if (! empty($response->toolCalls)) {
                    $assistantMessage['tool_calls'] = $response->toolCalls;
                }

                $messages[] = $assistantMessage;

                foreach ($response->toolCalls as $toolCall) {
                    $slug = $toolCall['function']['name'] ?? '';
                    $arguments = $toolCall['function']['arguments'] ?? [];
                    $callId = $toolCall['id'] ?? $slug . '_' . $turn;

                    if (is_string($arguments)) {
                        $arguments = json_decode($arguments, true) ?: [];
                    }

                    // 执行工具
                    $toolResult = $this->executeToolSafely($slug, $arguments, $tenantId);

                    $toolCallsLog[] = [
                        'slug' => $slug,
                        'arguments' => $arguments,
                        'result' => $toolResult,
                        'turn' => $turn,
                    ];

                    // 追加 tool result 消息
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'content' => is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                    ];
                }

                // 最后一轮工具调用后再跑一次 LLM 获取总结
                if ($turn === $maxTurns - 1) {
                    $finalResponse = $this->aiTextService->chat($messages, $chatOptions);
                    $this->accumulateUsage($totalUsage, $finalResponse->usage);
                    $text = $finalResponse->content;
                }
            }

            $this->conversationContext->clear();

            // 如果循环结束但 text 仍为空，标记 partial
            if ($text === '' && ! empty($toolCallsLog)) {
                return new HeadlessResult(
                    text: '（执行达到最大轮次，未获得最终总结）',
                    toolCallsLog: $toolCallsLog,
                    tokenUsage: $totalUsage,
                    partial: true,
                );
            }

            return new HeadlessResult(
                text: $text,
                toolCallsLog: $toolCallsLog,
                tokenUsage: $totalUsage,
                partial: false,
            );
        } catch (\Throwable $e) {
            Log::warning('[HeadlessAgent] 执行异常（fail-open）', [
                'role' => $agentRole,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            $this->conversationContext->clear();

            return new HeadlessResult(
                text: '',
                toolCallsLog: $toolCallsLog,
                tokenUsage: $totalUsage,
                partial: true,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * 按 role 解析 agent 模板（builtin 优先）
     */
    private function resolveTemplate(string $agentRole): ?array
    {
        // 尝试从 builtin 模板查找
        $template = BuiltinAgentTemplates::findByKey($agentRole);

        if ($template !== null) {
            return $template;
        }

        // 尝试从下游扩展模板查找
        $registry = app(AgentTemplateRegistry::class);

        return $registry->findByKey($agentRole);
    }

    /**
     * 从模板工具列表过滤出 headless 可用的 slug 列表
     *
     * 规则：仅 L1 + 不在黑名单中
     */
    private function resolveToolSlugs(array $template): array
    {
        $templateTools = (array) ($template['tools'] ?? []);
        $validSlugs = [];

        foreach ($templateTools as $slug) {
            if (in_array($slug, self::BLACKLISTED_TOOLS, true)) {
                continue;
            }

            $tool = $this->toolRegistry->get($slug);

            if ($tool === null) {
                continue;
            }

            // 仅 L1（安全铁律）
            if ($tool->risk !== Tool::RISK_L1) {
                continue;
            }

            $validSlugs[] = $slug;
        }

        return $validSlugs;
    }

    /**
     * 安全执行工具（异常不上抛）
     */
    private function executeToolSafely(string $slug, array $arguments, int $tenantId): mixed
    {
        try {
            return $this->toolRegistry->execute($slug, $arguments, $tenantId);
        } catch (\Throwable $e) {
            Log::warning('[HeadlessAgent] 工具执行异常', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => "工具 [{$slug}] 执行失败：" . $e->getMessage()];
        }
    }

    /**
     * 累计 token 用量
     */
    private function accumulateUsage(array &$total, array $usage): void
    {
        $total['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $total['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
        $total['total_tokens'] += (int) ($usage['total_tokens'] ?? 0);
    }
}
