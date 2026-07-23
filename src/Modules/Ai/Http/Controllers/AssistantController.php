<?php

namespace MultiTenantSaas\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\DTOs\PageContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;
use MultiTenantSaas\Modules\Ai\Services\IntentRouter;

/**
 * 页面 AI 助手入口。
 *
 * 接收 PageContext + user_intent → IntentRouter 路由 → AgentRuntime::runStream 流式返回。
 * 写操作约束：写工具只产出草稿，落库由业务 Service + 人确认完成。
 *
 * @OA\Tag(
 *     name="AI 助手",
 *     description="页面级 AI 助手（意图路由 + 流式对话）"
 * )
 */
class AssistantController extends Controller
{
    public function __construct(
        private IntentRouter $intentRouter,
        private AgentRuntimeContract $agentRuntime,
        private AgentServiceContract $agentService,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * @OA\Post(
     *     path="/v1/ai/assistant",
     *     summary="页面 AI 助手（SSE 流式）",
     *     description="根据页面上下文自动路由到对应 Agent，流式返回回复。",
     *     tags={"AI 助手"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"route", "module", "user_intent"},
     *
     *         @OA\Property(property="route", type="string", example="marketing.campaign.create", description="前端路由"),
     *         @OA\Property(property="module", type="string", example="Marketing", description="模块名"),
     *         @OA\Property(property="user_intent", type="string", example="帮我写一段活动文案", description="用户自然语言意图"),
     *         @OA\Property(property="entity_type", type="string", nullable=true, example="campaign"),
     *         @OA\Property(property="entity_id", type="integer", nullable=true),
     *         @OA\Property(property="form_state", type="object", nullable=true, description="当前表单状态"),
     *         @OA\Property(property="visible_data_summary", type="string", nullable=true, description="页面可见数据摘要"),
     *         @OA\Property(property="conversation_id", type="integer", nullable=true, description="续接已有会话")
     *     )),
     *
     *     @OA\Response(response=200, description="SSE 流式响应"),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=403, description="租户上下文缺失"),
     *     @OA\Response(response=404, description="无可用 Agent"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function handle(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'route' => 'required|string|max:255',
            'module' => 'required|string|max:100',
            'user_intent' => 'required|string|max:32000',
            'entity_type' => 'nullable|string|max:100',
            'entity_id' => 'nullable|integer',
            'form_state' => 'nullable|array',
            'visible_data_summary' => 'nullable|string|max:10000',
            'conversation_id' => 'nullable|integer',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();

        // 构建页面上下文
        $pageContext = PageContext::fromArray($validated);

        // 意图路由
        $agentSlug = $this->intentRouter->route($pageContext);

        if ($agentSlug === null) {
            return response()->json([
                'success' => false,
                'message' => '当前页面暂无可用的 AI 助手。',
            ], 404);
        }

        // 查找 Agent（按 slug + 租户）
        $agent = Agent::where('tenant_id', $tenantId)
            ->where('slug', $agentSlug)
            ->where('status', 'active')
            ->first();

        if (! $agent) {
            return response()->json([
                'success' => false,
                'message' => "AI 助手 [{$agentSlug}] 未配置或已停用。",
            ], 404);
        }

        // 获取或创建会话
        $conversation = $this->resolveConversation($tenantId, $agent->agent_id, $validated['conversation_id'] ?? null);

        // 组装消息（注入页面上下文）
        $message = $this->buildMessage($pageContext);

        // 流式响应
        return $this->streamResponse($agent->agent_id, $conversation->conversation_id, $message);
    }

    /**
     * 获取或创建会话。
     */
    private function resolveConversation(int $tenantId, int $agentId, ?int $conversationId): AgentConversation
    {
        if ($conversationId) {
            $conversation = AgentConversation::where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->where('agent_id', $agentId)
                ->first();

            if ($conversation) {
                return $conversation;
            }
        }

        return AgentConversation::create([
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'channel' => 'assistant',
            'subject' => '页面助手会话',
            'status' => 'active',
        ]);
    }

    /**
     * 组装带页面上下文的消息。
     */
    private function buildMessage(PageContext $ctx): string
    {
        $contextBlock = $ctx->toPromptContext();

        return "[页面上下文]\n{$contextBlock}\n\n[用户请求]\n{$ctx->userIntent}";
    }

    /**
     * SSE 流式响应。
     */
    private function streamResponse(int $agentId, int $conversationId, string $message): StreamedResponse
    {
        return response()->stream(function () use ($agentId, $conversationId, $message) {
            $generator = $this->agentRuntime->runStream($agentId, $conversationId, $message);

            foreach ($generator as $chunk) {
                if ($chunk instanceof StreamChunk) {
                    echo 'data: '.json_encode([
                        'type' => $chunk->type,
                        'content' => $chunk->content,
                        'metadata' => $chunk->metadata ?? null,
                    ], JSON_UNESCAPED_UNICODE)."\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
