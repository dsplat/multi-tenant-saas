<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\ActionConfirmService;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;

class ToolExecuteController extends AiStreamingController
{
    public function __construct(
        TenantContextContract $tenantContext,
        AgentServiceContract $agentService,
        private ToolRegistry $toolRegistry,
        private ActionConfirmService $actionConfirm,
        private ToolConversationContext $conversationContext,
    ) {
        parent::__construct($tenantContext, $agentService);
    }

    /**
     * @OA\Post(
     *     path="/v1/ai-streaming/tools/execute",
     *     summary="执行 Agent 工具（Node 引擎回调）",
     *     description="LLM 触发 tool_call 时 Node 引擎回调本端点，由 PHP 在租户上下文中执行工具并返回结果。仅允许执行 Agent 已授权（agent.tools）的工具。",
     *     tags={"AI 流式网关"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"agent_id", "tool"},
     *
     *         @OA\Property(property="agent_id", type="integer", example=1),
     *         @OA\Property(property="tool", type="string", description="工具 slug", example="order_query"),
     *         @OA\Property(property="arguments", type="object", description="LLM 生成的工具入参"),
     *         @OA\Property(property="conversation_id", type="integer", nullable=true, description="会话 ID（L2 确认令牌绑定会话，Node 从 resolve 结果附带）")
     *     )),
     *
     *     @OA\Response(response=200, description="工具执行结果（L2 工具返回 pending_confirmation 确认载荷）"),
     *     @OA\Response(response=403, description="工具未授权给该 Agent"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前团队")
     * )
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'agent_id' => ['required', 'integer'],
            'tool' => ['required', 'string', 'max:100'],
            'arguments' => ['sometimes', 'array'],
            'conversation_id' => ['sometimes', 'nullable', 'integer'],
            'tool_call_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $tenantId = $this->resolveTenantId();
        $agent = $this->ensureAgentForTenant((int) $data['agent_id'], $tenantId);

        // 越权防线：只允许执行 Agent 授权的工具（DB 快照 ∪ 模板，与 resolve 下发口径一致）
        $allowedTools = $agent->effectiveTools();
        if (! in_array($data['tool'], $allowedTools, true)) {
            return response()->json([
                'success' => false,
                'message' => "工具 [{$data['tool']}] 未授权给该 Agent",
            ], 403);
        }

        // L2 确认门：需用户确认的变更类工具不直接执行，签发一次性确认令牌后
        // 返回 pending_confirmation 载荷：经 a: 帧透传前端渲染确认卡片，同时交还
        // LLM 作为观察结果（提示其告知用户等待确认，不要重试）。
        // 用户确认后由 confirm-action 端点消费令牌执行（RBAC+审计+LLM 续答闭环）。
        $toolDef = $this->toolRegistry->get($data['tool']);
        if ($toolDef !== null && $toolDef->requiresConfirmation()) {
            $conversationId = (int) ($data['conversation_id'] ?? 0);
            $conversationValid = $conversationId > 0 && AgentConversation::where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->exists();
            if (! $conversationValid) {
                // 无合法会话归属无法签发令牌（确认端点校验会话绑定），保持拒执防绕过
                return response()->json([
                    'success' => false,
                    'message' => "工具 [{$data['tool']}] 需用户确认后执行，请求缺少合法会话归属",
                ], 403);
            }

            // 同轮交互互斥门：会话已有选项卡待点选时，拦截 L2 确认卡，
            // 杜绝轻量模型同轮并行「写操作 + ask_user_choice」双卡弹出；
            // 错误作为观察结果交还 LLM 自愈（等用户点选后再调用）
            if ($this->actionConfirm->hasChoicePending($tenantId, $conversationId)) {
                return response()->json([
                    'success' => true,
                    'data' => ['result' => [
                        'error' => true,
                        'message' => '选项卡已展示在用户对话区，正在等待用户点选，本轮不得再弹确认卡。'
                            . '请告知用户先完成上方选择；用户点选（如「满意，可以定稿」）后的下一轮再调用本工具。严禁重试。',
                    ]],
                ]);
            }

            $arguments = (array) ($data['arguments'] ?? []);
            // LLM 原生 tool_call id 随令牌存储：确认后续答时 tool 消息据此与 assistant.tool_calls 配对
            $issued = $this->actionConfirm->issue(
                $tenantId, $conversationId, $data['tool'], $arguments, $data['tool_call_id'] ?? null,
            );

            return response()->json([
                'success' => true,
                'data' => ['result' => [
                    'action' => 'pending_confirmation',
                    'status' => '已提交用户确认，等待用户在确认卡片上操作。确认卡片展示在本条回复下方的对话区（向用户描述卡片位置时只说「下方」，严禁说「上方」）。不要重复调用该工具，告知用户确认后将自动执行即可。',
                    'token' => $issued['token'],
                    'args_hash' => $issued['args_hash'],
                    'expires_in' => $issued['expires_in'],
                    'tool_slug' => $data['tool'],
                    'tool_name' => $toolDef->name,
                    'arguments' => $arguments,
                    'conversation_id' => $conversationId,
                ]],
            ]);
        }

        // 会话上下文注入：会话感知类工具（如任务化长工具需绑定会话做断连兜底）
        // 依赖 ToolConversationContext；scoped 绑定保证每请求隔离
        $this->conversationContext->clear();
        if (isset($data['conversation_id'])) {
            $this->conversationContext->set((int) $data['conversation_id']);
        }

        // 同轮交互互斥门（反向）：会话已有 L2 确认卡待操作时，拦截 ask_user_choice，
        // 避免确认卡与选项卡双卡并存干扰用户；错误交还 LLM 自愈（只引导用户看确认卡）
        $executeConversationId = (int) ($data['conversation_id'] ?? 0);
        if ($data['tool'] === 'ask_user_choice'
            && $this->actionConfirm->hasConfirmPending($tenantId, $executeConversationId)) {
            return response()->json([
                'success' => true,
                'data' => ['result' => [
                    'error' => true,
                    'message' => '本会话已有待确认的执行卡片，不得再发选项卡干扰用户。'
                        . '请只引导用户在确认卡片上点「确认执行」或「取消」，不要重复征询。',
                ]],
            ]);
        }

        // 同轮连发拦截：本轮已发出选项卡（选择中标记未清除）时，拦截后续 ask_user_choice。
        // 轻量模型会单轮连发多张选项卡「自问自答」跑完整问卷；每轮只允许一张选项卡，
        // 标记在用户应答（新轮到达）时清除，下一轮可正常再问。错误交还 LLM 自愈（停下等点选）
        if ($data['tool'] === 'ask_user_choice'
            && $this->actionConfirm->hasChoicePending($tenantId, $executeConversationId)) {
            return response()->json([
                'success' => true,
                'data' => ['result' => [
                    'error' => true,
                    'message' => '本轮已有一张选项卡正在等待用户点选，不得连续发第二张选项卡。'
                        . '请立即停下，正文只引导用户完成上方选择；用户点选回传后的下一轮再问下一个问题。严禁重试。',
                ]],
            ]);
        }

        // ToolRegistry::execute 内部已将处理器异常封装为 ['error'=>true, ...]，
        // 此处仅需兜底工具未注册等注册表层异常
        try {
            $result = $this->toolRegistry->execute(
                $data['tool'],
                (array) ($data['arguments'] ?? []),
                $tenantId
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // 选项卡成功发出：置会话级选择中标记（供 L2 确认门拦截同轮并行写操作）
        if ($data['tool'] === 'ask_user_choice'
            && is_array($result) && empty($result['error']) && $executeConversationId > 0) {
            $this->actionConfirm->markChoicePending($tenantId, $executeConversationId);
        }

        return response()->json([
            'success' => true,
            'data' => ['result' => $result],
        ]);
    }
}
