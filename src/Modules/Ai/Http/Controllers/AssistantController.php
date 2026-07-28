<?php

namespace MultiTenantSaas\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\DTOs\PageContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;

/**
 * AI 小助手入口（主入口，非兜底）。
 *
 * 系统小助手（system_secretary）是租户运营人员的唯一交互主入口，
 * 全程可用、平台买单、不消耗租户额度。
 * 小助手通过 list_agents / delegate_to_agent / enable_agent 工具
 * 智能调度数字员工完成专业操作。
 *
 * 路由逻辑：
 * - 前端显式传 agent_id（转派后续接）→ 直达目标员工
 * - 否则 → 一律由系统小助手接手（主入口）
 *
 * @OA\Tag(
 *     name="AI 助手",
 *     description="AI 小助手（主入口 + 流式对话 + 数字员工调度）"
 * )
 */
class AssistantController extends Controller
{
    public function __construct(
        private AgentRuntimeContract $agentRuntime,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * @OA\Post(
     *     path="/v1/ai/assistant",
     *     summary="AI 小助手（SSE 流式）",
     *     description="系统小助手为唯一主入口，智能调度数字员工完成操作。传 agent_id 可直达转派目标。",
     *     tags={"AI 助手"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"user_intent"},
     *
     *         @OA\Property(property="user_intent", type="string", example="帮我创建一个抽奖活动", description="用户自然语言意图"),
     *         @OA\Property(property="route", type="string", nullable=true, example="marketing.campaign.create", description="当前前端路由"),
     *         @OA\Property(property="module", type="string", nullable=true, example="Marketing", description="当前模块名"),
     *         @OA\Property(property="entity_type", type="string", nullable=true, example="campaign"),
     *         @OA\Property(property="entity_id", type="integer", nullable=true),
     *         @OA\Property(property="form_state", type="object", nullable=true, description="当前表单状态"),
     *         @OA\Property(property="visible_data_summary", type="string", nullable=true, description="页面可见数据摘要"),
     *         @OA\Property(property="conversation_id", type="integer", nullable=true, description="续接已有会话"),
     *         @OA\Property(property="agent_id", type="integer", nullable=true, description="转派后续接目标员工")
     *     )),
     *
     *     @OA\Response(response=200, description="SSE 流式响应"),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=503, description="小助手未初始化")
     * )
     */
    public function handle(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'route' => 'nullable|string|max:255',
            'module' => 'nullable|string|max:100',
            'user_intent' => 'required|string|max:32000',
            'entity_type' => 'nullable|string|max:100',
            'entity_id' => 'nullable|integer',
            'form_state' => 'nullable|array',
            'visible_data_summary' => 'nullable|string|max:10000',
            'conversation_id' => 'nullable|integer',
            'agent_id' => 'nullable|integer',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();

        // 构建页面上下文
        $pageContext = PageContext::fromArray($validated);

        // 路由策略：
        // 1. 前端显式传 agent_id（转派后续接）→ 直达目标员工
        // 2. 否则 → 系统小助手（主入口，全程可用）
        $agent = null;

        if (! empty($validated['agent_id'])) {
            $agent = Agent::where('tenant_id', $tenantId)
                ->where('agent_id', $validated['agent_id'])
                ->where('enabled', true)
                ->first();
        }

        // 主入口：系统小助手（不是兜底，是唯一入口）
        if (! $agent) {
            $agent = Agent::where('tenant_id', $tenantId)
                ->where('role', 'system_secretary')
                ->where('enabled', true)
                ->first();
        }

        if (! $agent) {
            return response()->json([
                'success' => false,
                'message' => 'AI 小助手尚未初始化，请联系平台管理员执行 secretary:install。',
            ], 503);
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
     * 检查 AI 小助手可用性（平台级开关）。
     *
     * 小助手是平台级预配置、全程可用的主入口，
     * 仅当平台管理员显式关闭 secretary 时才不可用。
     *
     * GET /v1/ai/assistant/availability
     */
    public function availability(Request $request): JsonResponse
    {
        // 平台级开关：config('ai.secretary.enabled')
        // 小助手全程可用，不依赖租户配置或数字员工是否启用
        $available = config('ai.secretary.enabled', true);

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $available,
            ],
        ]);
    }

    /**
     * 会话历史（刷新恢复用）。
     *
     * GET /v1/ai/assistant/history?conversation_id=X&limit=50
     * 前端刷新后凭 localStorage 中的 conversation_id 拉取历史消息恢复面板。
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();

        $conversation = AgentConversation::where('tenant_id', $tenantId)
            ->where('conversation_id', $validated['conversation_id'])
            ->first();

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或已过期。',
            ], 404);
        }

        $limit = (int) ($validated['limit'] ?? 50);

        // 只取用户可见轮次（过滤 tool 结果与空的工具调用轮次），倒序取最近 N 条后正序返回
        // 注：message_id 为全局 ID（非单调递增），排序以 created_at 为准
        $messages = AgentConversationMessage::where('conversation_id', $conversation->conversation_id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('message_id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'message_id' => $m->message_id,
                'role' => $m->role,
                'content' => (string) $m->content,
                'created_at' => $m->created_at?->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'conversation_id' => $conversation->conversation_id,
                'agent_id' => $conversation->agent_id,
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * SSE 流式响应。
     *
     * 协议（data: JSON\n\n）：
     *  - {"type":"meta","content":{...}}        会话元信息（conversation_id/agent_id，首帧下发）
     *  - {"type":"text","content":"..."}        增量文本
     *  - {"type":"tool_call","content":[...]}   工具调用决策（前端展示“正在调用 XX”）
     *  - {"type":"done","metadata":{...}}       流结束
     */
    private function streamResponse(int $agentId, int $conversationId, string $message): StreamedResponse
    {
        return response()->stream(function () use ($agentId, $conversationId, $message) {
            // 首帧下发会话元信息，前端持久化 conversation_id 以支持刷新续接
            $this->emit(['type' => 'meta', 'content' => ['conversation_id' => $conversationId, 'agent_id' => $agentId]]);

            $generator = $this->agentRuntime->runStream($agentId, $conversationId, $message);

            foreach ($generator as $chunk) {
                if (! $chunk instanceof StreamChunk) {
                    continue;
                }

                // 增量文本
                if ($chunk->text !== '') {
                    $this->emit(['type' => 'text', 'content' => $chunk->text]);
                }

                // 工具调用决策
                if ($chunk->hasToolCalls()) {
                    // 检测 suggest_form_fill 工具 → 转为 form_fill 类型
                    $formFill = $this->extractFormFill($chunk->toolCalls);
                    if ($formFill) {
                        $this->emit(['type' => 'form_fill', 'content' => $formFill]);
                    } elseif ($workflow = $this->extractWorkflow($chunk->toolCalls)) {
                        $this->emit(['type' => 'workflow', 'content' => $workflow]);
                    } else {
                        $this->emit(['type' => 'tool_call', 'content' => $chunk->toolCalls]);
                    }
                }

                // 流结束
                if ($chunk->isFinished()) {
                    $this->emit(['type' => 'done', 'content' => '', 'metadata' => ['finish_reason' => $chunk->finishReason]]);
                }
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

    /**
     * 输出单个 SSE 事件。
     */
    private function emit(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * 从工具调用中提取 suggest_form_fill 结果。
     * Agent 调用此工具时，参数即为表单填充建议。
     */
    private function extractFormFill(array $toolCalls): ?array
    {
        foreach ($toolCalls as $tc) {
            $slug = is_object($tc) ? ($tc->slug ?? $tc->name ?? '') : ($tc['slug'] ?? $tc['name'] ?? '');
            if ($slug === 'suggest_form_fill') {
                $args = is_object($tc) ? ($tc->arguments ?? []) : ($tc['arguments'] ?? []);
                return [
                    'fields' => $args['fields'] ?? $args,
                    'explanation' => $args['explanation'] ?? null,
                    'field_notes' => $args['field_notes'] ?? null,
                    'confidence' => $args['confidence'] ?? 0.8,
                ];
            }
        }

        return null;
    }

    /**
     * 从工具调用中提取 suggest_workflow 结果。
     * Agent 调用此工具时，参数即为工作流编排建议。
     */
    private function extractWorkflow(array $toolCalls): ?array
    {
        foreach ($toolCalls as $tc) {
            $slug = is_object($tc) ? ($tc->slug ?? $tc->name ?? '') : ($tc['slug'] ?? $tc['name'] ?? '');
            if ($slug === 'suggest_workflow') {
                $args = is_object($tc) ? ($tc->arguments ?? []) : ($tc['arguments'] ?? []);
                return [
                    'name' => $args['name'] ?? '未命名流程',
                    'steps' => $args['steps'] ?? [],
                    'submit_endpoint' => $args['submit_endpoint'] ?? null,
                    'submit_payload' => $args['submit_payload'] ?? null,
                    'explanation' => $args['explanation'] ?? null,
                ];
            }
        }

        return null;
    }
}
