<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;

/**
 * @OA\Tag(
 *     name="AI 流式网关",
 *     description="Node SSE 引擎契约 API（resolve / tools/execute / usage/report）"
 * )
 */
class ResolveController extends AiStreamingController
{
    public function __construct(
        TenantContextContract $tenantContext,
        AgentServiceContract $agentService,
        private AiUsageService $usageService,
        private ToolRegistry $toolRegistry,
    ) {
        parent::__construct($tenantContext, $agentService);
    }

    /**
     * @OA\Post(
     *     path="/v1/ai-streaming/resolve",
     *     summary="解析流式会话配置（Node 引擎回调）",
     *     description="Node SSE 引擎发起流式请求前回调本端点：完成鉴权、租户识别、配额/预算前置检查，返回 Agent 的模型端点、系统提示词与工具定义。",
     *     tags={"AI 流式网关"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"agent_id"},
     *
     *         @OA\Property(property="agent_id", type="integer", example=1)
     *     )),
     *
     *     @OA\Response(response=200, description="会话配置（model/base_url/api_key/system_prompt/tools/...）"),
     *     @OA\Response(response=402, description="配额或预算超限"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前团队"),
     *     @OA\Response(response=503, description="流式服务已关闭")
     * )
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'agent_id' => ['required', 'integer'],
        ]);

        $tenantId = $this->resolveTenantId();
        $agent = $this->ensureAgentForTenant((int) $data['agent_id'], $tenantId);

        // 配额/预算前置检查（超限即拒绝，不产生任何 LLM 开销）
        try {
            $this->usageService->checkQuota('text');
            $this->usageService->checkBudget();
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 402);
        }

        $modelConfig = (array) ($agent->model_config ?? []);
        $providerName = $modelConfig['provider'] ?? config('ai.default_provider', 'openai');
        $providerConfig = (array) config("ai.providers.{$providerName}", []);

        $baseUrl = $providerConfig['base_url'] ?? $providerConfig['url'] ?? null;
        if (empty($baseUrl)) {
            return response()->json([
                'success' => false,
                'message' => "Provider [{$providerName}] 未配置 base_url",
            ], 422);
        }

        $payload = [
            'tenant_id' => $tenantId,
            'agent_id' => (int) $agent->agent_id,
            'provider' => $providerName,
            'model' => $modelConfig['model'] ?? config('ai.default_model'),
            'base_url' => rtrim((string) $baseUrl, '/'),
            'system_prompt' => (string) ($agent->system_prompt ?? ''),
            'temperature' => (float) ($modelConfig['temperature'] ?? 0.7),
            'max_tokens' => (int) ($modelConfig['max_tokens'] ?? 4096),
            'max_tool_calls' => (int) ($modelConfig['max_tool_calls'] ?? config('ai-streaming.max_tool_calls', 5)),
            // OpenAI Function Calling 格式工具定义（执行仍回调 PHP）
            'tools' => $this->toolRegistry->getToolDefinitions((array) ($agent->tools ?? [])),
        ];

        // direct 模式：下发 api_key（仅限 Node 与 PHP 同机/内网回环链路）
        if (config('ai-streaming.key_delivery', 'direct') === 'direct') {
            $payload['api_key'] = (string) ($providerConfig['api_key'] ?? $providerConfig['key'] ?? '');
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }
}
