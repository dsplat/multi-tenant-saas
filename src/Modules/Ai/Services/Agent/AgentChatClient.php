<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Generator;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;

/**
 * Agent AI 调用客户端
 *
 * 职责：AI 推理调用（含降级容错）、模型配置解析、chat 选项构建、
 * 流式首 chunk 前换道、工具定义解析、token 用量累加。
 *
 * 从 AgentRuntime 提取，纯方法搬迁无逻辑变更。
 */
class AgentChatClient
{
    public function __construct(
        private AiTextServiceContract $aiService,
        private ToolRegistryContract $toolRegistry,
    ) {}

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
    public function chatWithFallback(
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
     * "起跑前换道"：显式驱动 Generator 至首 chunk，若在此之前失败
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
    public function streamChatWithFirstChunkFallback(
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
     * 构建 chat 调用选项
     */
    public function buildChatOptions(Agent $agent, array $toolDefinitions = [], array $overrides = []): array
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
    public function resolveModelConfig(Agent $agent): array
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
     * 解析 Agent 的有效工具列表（Agent::effectiveTools 基础上支持渠道级排除）
     *
     * @param  list<string>  $excludeTools  需排除的工具 slug 列表
     * @return list<string>
     */
    public function resolveEffectiveTools(Agent $agent, array $excludeTools = []): array
    {
        $tools = $agent->effectiveTools();

        if ($excludeTools === []) {
            return $tools;
        }

        return array_values(array_diff($tools, $excludeTools));
    }

    /**
     * 累加 token 用量
     */
    public function accumulateUsage(array $total, array $usage): array
    {
        $total['prompt_tokens'] += $usage['prompt_tokens'] ?? 0;
        $total['completion_tokens'] += $usage['completion_tokens'] ?? 0;
        $total['total_tokens'] += $usage['total_tokens'] ?? 0;

        return $total;
    }

    /**
     * 构建流式 fallback 调用选项
     *
     * 无 fallback 配置、或 fallback 与主选项 provider+model 完全相同
     *（原地重试无意义）时返回 null。
     */
    public function buildFallbackChatOptions(Agent $agent, array $chatOptions): ?array
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
