<?php

namespace MultiTenantSaas\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Http\Requests\SendMessageRequest;
use MultiTenantSaas\Modules\Ai\Http\Requests\StartChatRequest;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;
use MultiTenantSaas\Modules\Conversation\Http\Resources\ConversationResource;
use MultiTenantSaas\Modules\Conversation\Http\Resources\MessageResource;

/**
 * @OA\Tag(
 *     name="Agent 对话",
 *     description="Agent 对话 API + SSE 流式端点（§6.2）"
 * )
 */
class AgentChatController extends Controller
{
    public function __construct(
        private AgentRuntimeContract $agentRuntime,
        private AgentServiceContract $agentService,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * @OA\Post(
     *     path="/v1/agents/{agentId}/chat",
     *     summary="发起对话（SSE 流式）",
     *     description="创建新会话，通过 SSE 流式输出 Agent 回复。服务端使用 AgentRuntime.runStream() 逐 chunk 推送。",
     *     tags={"Agent 对话"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"message"},
     *
     *         @OA\Property(property="message", type="string", maxLength=32000, example="你好，请帮我查询订单"),
     *         @OA\Property(property="customer_id", type="integer", nullable=true, description="客户 ID"),
     *         @OA\Property(property="staff_id", type="integer", nullable=true, description="客服 ID"),
     *         @OA\Property(property="channel", type="string", maxLength=20, nullable=true, description="渠道标识", example="web"),
     *         @OA\Property(property="subject", type="string", maxLength=255, nullable=true, description="会话主题"),
     *         @OA\Property(property="options", type="object", nullable=true,
     *             @OA\Property(property="max_tool_calls", type="integer", minimum=1),
     *             @OA\Property(property="temperature", type="number", minimum=0, maximum=2)
     *         )
     *     )),
     *
     *     @OA\Response(response=200, description="SSE 流式响应（Content-Type: text/event-stream）"),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function startChat(StartChatRequest $request, int $agentId): StreamedResponse|JsonResponse
    {
        $tenantId = $this->resolveTenantId();
        $this->ensureAgentForTenant($agentId, $tenantId);

        // 创建会话（message_count 由数据库默认值初始化，运行时在保存消息时更新）
        $conversation = AgentConversation::create([
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'customer_id' => $request->input('customer_id'),
            'staff_id' => $request->input('staff_id'),
            'channel' => $request->input('channel', 'web'),
            'subject' => $request->input('subject'),
            'status' => 'active',
            'token_usage' => null,
            'metadata' => null,
        ]);

        $conversationId = (int) $conversation->conversation_id;
        $message = $request->validated('message');
        $options = $request->validated('options', []);

        return $this->streamAgentResponse($agentId, $conversationId, $message, $options);
    }

    /**
     * @OA\Post(
     *     path="/v1/agents/{agentId}/chat/{conversationId}",
     *     summary="在已有会话中发消息（SSE 流式）",
     *     description="向已有会话追加用户消息，通过 SSE 流式输出 Agent 回复。",
     *     tags={"Agent 对话"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="conversationId", in="path", required=true, description="会话 ID", @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"message"},
     *
     *         @OA\Property(property="message", type="string", maxLength=32000, example="继续追问"),
     *         @OA\Property(property="options", type="object", nullable=true,
     *             @OA\Property(property="max_tool_calls", type="integer", minimum=1),
     *             @OA\Property(property="temperature", type="number", minimum=0, maximum=2)
     *         )
     *     )),
     *
     *     @OA\Response(response=200, description="SSE 流式响应（Content-Type: text/event-stream）"),
     *     @OA\Response(response=400, description="会话已结束"),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent/会话不存在或不属于当前租户"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function sendMessage(SendMessageRequest $request, int $agentId, int $conversationId): StreamedResponse|JsonResponse
    {
        $tenantId = $this->resolveTenantId();
        $this->ensureAgentForTenant($agentId, $tenantId);

        // 验证会话存在、属于当前租户且关联指定 Agent
        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->first();

        if ($conversation === null) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或不属于当前租户',
            ], 404);
        }

        if ($conversation->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => '会话已结束，无法发送新消息',
            ], 400);
        }

        $message = $request->validated('message');
        $options = $request->validated('options', []);

        return $this->streamAgentResponse($agentId, $conversationId, $message, $options);
    }

    /**
     * @OA\Get(
     *     path="/v1/agents/{agentId}/conversations",
     *     summary="获取 Agent 的对话列表",
     *     tags={"Agent 对话"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="page", in="query", description="页码", @OA\Schema(type="integer", default=1)),
     *
     *     @OA\Response(response=200, description="对话列表（分页）", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *         @OA\Property(property="meta", type="object",
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="last_page", type="integer"),
     *             @OA\Property(property="per_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )),
     *
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function conversations(Request $request, int $agentId): JsonResponse
    {
        $tenantId = $this->resolveTenantId();
        $this->ensureAgentForTenant($agentId, $tenantId);

        $conversations = AgentConversation::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => ConversationResource::collection($conversations),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/conversations/{conversationId}",
     *     summary="获取对话详情",
     *     tags={"Agent 对话"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="conversationId", in="path", required=true, description="会话 ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="对话详情", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object")
     *     )),
     *
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="会话不存在或不属于当前租户")
     * )
     */
    public function showConversation(Request $request, int $conversationId): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或不属于当前租户',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/conversations/{conversationId}/messages",
     *     summary="获取对话消息列表",
     *     tags={"Agent 对话"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="conversationId", in="path", required=true, description="会话 ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="page", in="query", description="页码", @OA\Schema(type="integer", default=1)),
     *
     *     @OA\Response(response=200, description="消息列表（分页）", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *         @OA\Property(property="meta", type="object",
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="last_page", type="integer"),
     *             @OA\Property(property="per_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )),
     *
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="会话不存在或不属于当前租户")
     * )
     */
    public function messages(Request $request, int $conversationId): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        // 验证会话存在且属于当前租户
        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或不属于当前租户',
            ], 404);
        }

        $messages = AgentConversationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => MessageResource::collection($messages),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/v1/conversations/{conversationId}",
     *     summary="删除对话",
     *     tags={"Agent 对话"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="conversationId", in="path", required=true, description="会话 ID", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="删除成功", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="会话已删除")
     *     )),
     *
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="会话不存在或不属于当前租户")
     * )
     */
    public function deleteConversation(Request $request, int $conversationId): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或不属于当前租户',
            ], 404);
        }

        // 删除关联消息
        AgentConversationMessage::where('conversation_id', $conversationId)->delete();

        // 删除会话
        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => '会话已删除',
        ]);
    }

    /**
     * 构建 SSE 流式响应
     *
     * 通过 AgentRuntime.runStream() 获取 StreamChunk Generator，
     * 逐块输出 SSE 格式数据，末尾发送 [DONE] 标记。
     */
    private function streamAgentResponse(
        int $agentId,
        int $conversationId,
        string $message,
        array $options = [],
    ): StreamedResponse {
        $agentRuntime = $this->agentRuntime;

        return response()->stream(function () use ($agentRuntime, $agentId, $conversationId, $message, $options) {
            try {
                /** @var StreamChunk $chunk */
                foreach ($agentRuntime->runStream($agentId, $conversationId, $message, $options) as $chunk) {
                    // 输出 SSE 格式数据
                    echo 'data: ' . json_encode([
                        'text' => $chunk->text,
                        'tool_calls' => $chunk->toolCalls,
                        'finish_reason' => $chunk->finishReason,
                    ], JSON_UNESCAPED_UNICODE) . "\n\n";

                    ob_flush();
                    flush();
                }
            } catch (\Throwable $e) {
                Log::error('AgentChatController: 流式对话异常', [
                    'agent_id' => $agentId,
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);

                echo 'data: ' . json_encode([
                    'text' => "\n\n[对话处理过程中发生错误]",
                    'tool_calls' => [],
                    'finish_reason' => 'error',
                ], JSON_UNESCAPED_UNICODE) . "\n\n";

                ob_flush();
                flush();
            }

            // 发送流结束标记
            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * 从 TenantContext 解析当前租户 ID
     */
    private function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            abort(403, '无法识别当前租户');
        }

        return (int) $tenantId;
    }

    /**
     * 验证 Agent 存在且属于当前租户，否则中止请求
     */
    private function ensureAgentForTenant(int $agentId, int $tenantId): object
    {
        $agent = $this->agentService->find($agentId);
        if ($agent === null || (int) $agent->tenant_id !== $tenantId) {
            abort(404, 'Agent 不存在或不属于当前租户');
        }

        return $agent;
    }
}
