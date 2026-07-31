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
     *     description="流结束（onFinish）时 Node 引擎回调本端点，把本轮 user/assistant 消息落库到 agent_conversation_messages（Node 保持无状态哑管道，落库语义归 PHP）。",
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
     *         @OA\Property(property="tool_calls", type="array", nullable=true, description="本轮工具调用（steps 展平）", @OA\Items(type="object"))
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
        $now = now();

        $userContent = $this->stripContextWrapper((string) ($data['user_message'] ?? ''));
        if ($userContent !== '') {
            AgentConversationMessage::create([
                'conversation_id' => (int) $conversation->conversation_id,
                'role' => 'user',
                'content' => $userContent,
                'metadata' => ['source' => 'ai-streaming'],
                'created_at' => $now,
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
                'created_at' => $now,
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
