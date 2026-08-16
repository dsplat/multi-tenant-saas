<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Events\ConversationStarted;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentProvisioningService;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Campaign\Services\ThreadDigestService;

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
     *         @OA\Property(property="agent_id", type="integer", nullable=true, example=1, description="省略时兑底到租户的系统小助手（role=system_secretary）"),
     *         @OA\Property(property="conversation_id", type="integer", nullable=true, example=100, description="续接已有会话；省略或无效时创建新会话")
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
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $tenantId = $this->resolveTenantId();

        // agent_id 省略时兑底到系统小助手（与 AssistantController 主入口一致），
        // 前端小助手无需预先知道秘书的 agent_id 即可直连 Node 引擎
        if (empty($data['agent_id'])) {
            $secretary = Agent::where('tenant_id', $tenantId)
                ->where('role', 'system_secretary')
                ->where('enabled', true)
                ->first();

            // 懒开通：首次打开小助手对话时自动克隆秘书（无需用户确认，
            // 系统最基本能力；审批时不再预装）
            if ($secretary === null) {
                $secretary = app(AgentProvisioningService::class)->ensureSecretary((int) $tenantId);
            }

            if ($secretary === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'AI 小助手初始化失败，请联系平台管理员执行 secretary:install。',
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

        // 秘书强制走平台级配置（平台买单，不读租户维护的 model_config），
        // 其余 Agent 用租户 model_config（DB 存 preferred_* 键），与 AgentChatClient::resolveModelConfig 口径一致
        if ($agent->role === 'system_secretary' && config('ai.secretary.enabled', true)) {
            $providerName = (string) config('ai.secretary.provider', 'bailian');
            $modelName = (string) config('ai.secretary.model', 'qwen3.7-flash');
            $temperature = (float) config('ai.secretary.temperature', 0.3);
            $maxTokens = (int) config('ai.secretary.max_tokens', 2000);
        } else {
            $modelConfig = (array) ($agent->model_config ?? []);
            $providerName = (string) ($modelConfig['preferred_provider'] ?? config('ai.default_provider', 'openai'));
            $modelName = (string) ($modelConfig['preferred_model'] ?? config('ai.default_model'));
            $temperature = (float) ($modelConfig['temperature'] ?? 0.7);
            $maxTokens = (int) ($modelConfig['max_tokens'] ?? 4096);
        }
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
            // 会话续接/创建：消息落库归属（Node 经 2: data 帧下发给前端持久化）
            'conversation_id' => $this->resolveConversationId($tenantId, (int) $agent->agent_id, $data['conversation_id'] ?? null),
            'provider' => $providerName,
            'model' => $modelName,
            'base_url' => rtrim((string) $baseUrl, '/'),
            // 模板优先的有效 prompt（用户自定义过才用 DB 快照），与 AgentRuntime 兜底口径一致；
            // 系统小助手附加活跃脉络摘要（项目大脑 Phase 1b，ai.brain.enabled 默认关闭）
            'system_prompt' => $this->composeSystemPrompt($agent, $tenantId),
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            // max_tool_calls 秘书取平台级配置，其余 Agent 用租户配置（与 AgentRuntime 口径一致）
            'max_tool_calls' => $agent->effectiveMaxToolCalls((int) config('ai-streaming.max_tool_calls', 5)),
            // OpenAI Function Calling 格式工具定义（执行仍回调 PHP）。
            // effectiveTools = DB 快照 ∪ 模板最新工具，与 AgentRuntime 非流式链路一致。
            // L2 需确认工具照常下发：tools/execute 侧确认门拦截签发令牌，
            // 经前端确认卡片由用户确认后才真正执行（不降级风险语义）
            'tools' => $this->toolRegistry->getToolDefinitions($agent->effectiveTools()),
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
     * 有效 system_prompt + 活跃脉络摘要附录（项目大脑 Phase 1b）
     *
     * 仅系统小助手注入（resolve 也服务业务数字员工，不得污染其上下文）；
     * ai.brain.enabled 默认关闭；Campaign 模块未安装时静默跳过（软依赖，
     * AiStreaming 不声明对 campaign 包的 composer 依赖）。
     */
    private function composeSystemPrompt(Agent $agent, int $tenantId): string
    {
        $prompt = $agent->effectiveSystemPrompt();

        if ($agent->role !== 'system_secretary'
            || ! config('ai.brain.enabled')
            || ! class_exists(ThreadDigestService::class)) {
            return $prompt;
        }

        $digest = app(ThreadDigestService::class)->buildDigest($tenantId);

        return $digest === '' ? $prompt : $prompt."\n\n".$digest;
    }

    /**
     * 续接已有会话（校验租户与 Agent 归属），无效或缺省时创建新会话。
     *
     * channel 沿用 'assistant'：与 PHP 直连链路同一产品面（历史列表/继续聊/删除
     * 均按 channel=assistant 过滤），传输链路差异记录在 metadata.source。
     */
    private function resolveConversationId(int $tenantId, int $agentId, ?int $conversationId): int
    {
        if ($conversationId !== null && $conversationId > 0) {
            $existing = AgentConversation::where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->where('agent_id', $agentId)
                ->first();

            if ($existing !== null) {
                return (int) $existing->conversation_id;
            }
        }

        $conversation = AgentConversation::create([
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'channel' => 'assistant',
            'subject' => '页面助手会话',
            'status' => 'active',
            'metadata' => ['source' => 'ai-streaming'],
        ]);

        ConversationStarted::dispatch($tenantId, $agentId, (int) $conversation->conversation_id);

        return (int) $conversation->conversation_id;
    }
}
