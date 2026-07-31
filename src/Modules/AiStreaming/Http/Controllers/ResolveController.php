<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
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
     *
     *         @OA\Property(property="agent_id", type="integer", nullable=true, example=1, description="省略时兑底到租户的系统小助手（role=system_secretary）")
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
            'agent_id' => ['nullable', 'integer'],
        ]);

        $tenantId = $this->resolveTenantId();

        // agent_id 省略时兑底到系统小助手（与 AssistantController 主入口一致），
        // 前端小助手无需预先知道秘书的 agent_id 即可直连 Node 引擎
        if (empty($data['agent_id'])) {
            $secretary = Agent::where('tenant_id', $tenantId)
                ->where('role', 'system_secretary')
                ->where('enabled', true)
                ->first();

            if ($secretary === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'AI 小助手尚未初始化，请联系平台管理员执行 secretary:install。',
                ], 404);
            }

            $data['agent_id'] = (int) $secretary->agent_id;
        }

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
            // OpenAI Function Calling 格式工具定义（执行仍回调 PHP）。
            // effectiveTools = DB 快照 ∪ 模板最新工具，与 AgentRuntime 非流式链路一致。
            // 排除 L2 需确认工具：Node 链路暂无确认门，不下发即不会被 LLM 调用
            'tools' => $this->toolRegistry->getToolDefinitions($this->filterConfirmableTools($agent->effectiveTools())),
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

    /**
     * 过滤掉需用户确认的 L2 工具（Node 链路暂不支持确认门，
     * ToolExecuteController 侧有同样的拒执防线，双重保险）
     *
     * @param  string[]  $slugs
     * @return string[]
     */
    private function filterConfirmableTools(array $slugs): array
    {
        return array_values(array_filter($slugs, function (string $slug): bool {
            $tool = $this->toolRegistry->get($slug);

            return $tool === null || ! $tool->requiresConfirmation();
        }));
    }
}
