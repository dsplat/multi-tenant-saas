<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;

class ToolExecuteController extends AiStreamingController
{
    public function __construct(
        TenantContextContract $tenantContext,
        AgentServiceContract $agentService,
        private ToolRegistry $toolRegistry,
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
     *         @OA\Property(property="arguments", type="object", description="LLM 生成的工具入参")
     *     )),
     *
     *     @OA\Response(response=200, description="工具执行结果"),
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
        ]);

        $tenantId = $this->resolveTenantId();
        $agent = $this->ensureAgentForTenant((int) $data['agent_id'], $tenantId);

        // 越权防线：只允许执行 Agent 显式授权的工具
        $allowedTools = (array) ($agent->tools ?? []);
        if (! in_array($data['tool'], $allowedTools, true)) {
            return response()->json([
                'success' => false,
                'message' => "工具 [{$data['tool']}] 未授权给该 Agent",
            ], 403);
        }

        // L2 确认门防线：需用户确认的变更类工具不得经 Node 链路直接执行
        //（resolve 已不下发这类工具，此处是防绕过的兼底拒执）
        $toolDef = $this->toolRegistry->get($data['tool']);
        if ($toolDef !== null && $toolDef->requiresConfirmation()) {
            return response()->json([
                'success' => false,
                'message' => "工具 [{$data['tool']}] 需用户确认后执行，流式链路暂不支持",
            ], 403);
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

        return response()->json([
            'success' => true,
            'data' => ['result' => $result],
        ]);
    }
}
