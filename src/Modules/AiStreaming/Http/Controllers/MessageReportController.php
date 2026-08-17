<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;

class MessageReportController extends AiStreamingController
{
    /** 默认会话主题（首条用户消息到达时替换为摘要） */
    private const DEFAULT_SUBJECT = '页面助手会话';

    /**
     * @OA\Post(
     *     path="/v1/ai-streaming/messages/report",
     *     summary="上报流式会话消息（Node 引擎回调）",
     *     description="流结束（onFinish）时 Node 引擎回调本端点，把本轮 user/assistant 消息与流内工具执行结果落库到 agent_conversation_messages（Node 保持无状态哑管道，落库语义归 PHP）。tool_results 为可选扩展：老版本 Node 不传时行为不变（向后兼容）。",
     *     tags={"AI 流式网关"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"conversation_id"},
     *
     *         @OA\Property(property="conversation_id", type="integer", example=100),
     *         @OA\Property(property="agent_id", type="integer", nullable=true, example=1),
     *         @OA\Property(property="user_message", type="string", nullable=true, description="本轮用户消息（含前端包装的页面上下文，落库前剥离）"),
     *         @OA\Property(property="assistant_message", type="string", nullable=true, description="本轮 AI 回复全文"),
     *         @OA\Property(property="tool_calls", type="array", nullable=true, description="本轮工具调用（steps 展平）", @OA\Items(type="object")),
     *         @OA\Property(property="tool_results", type="array", nullable=true, description="本轮工具执行结果 [{tool_call_id, tool_name, content}]，在 assistant 消息之后逐条落 role=tool", @OA\Items(type="object"))
     *     )),
     *
     *     @OA\Response(response=200, description="落库成功"),
     *     @OA\Response(response=404, description="会话不存在或不属于当前团队"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'agent_id' => ['sometimes', 'nullable', 'integer'],
            'user_message' => ['sometimes', 'nullable', 'string'],
            'assistant_message' => ['sometimes', 'nullable', 'string'],
            'tool_calls' => ['sometimes', 'nullable', 'array'],
            'tool_results' => ['sometimes', 'nullable', 'array'],
        ]);

        $tenantId = $this->resolveTenantId();

        $conversation = AgentConversation::where('tenant_id', $tenantId)
            ->where('conversation_id', (int) $data['conversation_id'])
            ->first();

        if ($conversation === null) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或不属于当前团队',
            ], 404);
        }

        $saved = 0;
        // 顺序事实源：message_id 为无序随机全局 ID，历史重建按 created_at 排序；
        // 同批落库的 user → assistant → tool 逐条递增秒数，保证轮内顺序确定
        $now = now();
        $stamp = function () use (&$saved, $now): \Illuminate\Support\Carbon {
            return $now->copy()->addSeconds($saved);
        };

        $userContent = $this->stripContextWrapper((string) ($data['user_message'] ?? ''));
        if ($userContent !== '') {
            AgentConversationMessage::create([
                'conversation_id' => (int) $conversation->conversation_id,
                'role' => 'user',
                'content' => $userContent,
                'metadata' => ['source' => 'ai-streaming'],
                'created_at' => $stamp(),
            ]);
            $saved++;

            // 首条用户消息作为会话主题（历史列表可读性）
            if (empty($conversation->subject) || $conversation->subject === self::DEFAULT_SUBJECT) {
                $conversation->subject = mb_substr($userContent, 0, 50);
            }
        }

        $assistantContent = (string) ($data['assistant_message'] ?? '');
        $toolCalls = array_values(array_filter((array) ($data['tool_calls'] ?? [])));
        if ($assistantContent !== '' || $toolCalls !== []) {
            AgentConversationMessage::create([
                'conversation_id' => (int) $conversation->conversation_id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
                'metadata' => ['source' => 'ai-streaming'],
                'created_at' => $stamp(),
            ]);
            $saved++;
        }

        // 流内工具结果落库（L1 直执 + L2 确认后执行的结果均在 Node 流内产生）：
        // 严格 OpenAI 协议要求 tool 消息紧随携带 tool_calls 的 assistant 消息，
        // 落库顺序 user → assistant → tool，tool_call_id 直接取 LLM 原生 id（不靠猜）
        foreach (array_values(array_filter((array) ($data['tool_results'] ?? []))) as $result) {
            if (! is_array($result)) {
                continue;
            }
            $toolCallId = (string) ($result['tool_call_id'] ?? '');
            if ($toolCallId === '') {
                // 无 id 的 tool 消息无法与 tool_calls 配对，落库会污染历史重建，拒收
                continue;
            }
            AgentConversationMessage::create([
                'conversation_id' => (int) $conversation->conversation_id,
                'role' => 'tool',
                'content' => is_string($result['content'] ?? null) ? $result['content'] : json_encode($result['content'] ?? null, JSON_UNESCAPED_UNICODE),
                'tool_calls' => null,
                'tool_call_id' => $toolCallId,
                'metadata' => ['source' => 'ai-streaming', 'tool_name' => (string) ($result['tool_name'] ?? '')],
                'created_at' => $stamp(),
            ]);
            $saved++;
        }

        if ($saved > 0) {
            $conversation->message_count = (int) $conversation->message_count + $saved;
            $conversation->save();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'conversation_id' => (int) $conversation->conversation_id,
                'saved' => $saved,
            ],
        ]);
    }

    /**
     * 剥离前端包装的页面上下文，只落用户原话。
     *
     * 前端 buildContextMessage 格式：`[页面上下文]\n...\n\n[用户请求]\n<原话>`
     */
    private function stripContextWrapper(string $message): string
    {
        $marker = "[用户请求]\n";
        $pos = mb_strrpos($message, $marker);

        if ($pos !== false) {
            return trim(mb_substr($message, $pos + mb_strlen($marker)));
        }

        return trim($message);
    }
}
