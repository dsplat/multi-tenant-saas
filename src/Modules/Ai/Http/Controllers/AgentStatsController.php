<?php

namespace MultiTenantSaas\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Http\Resources\ToolLogResource;
use MultiTenantSaas\Modules\Ai\Models\AgentToolLog;

/**
 * @OA\Tag(
 *     name="Agent 监控",
 *     description="Agent 的使用统计、Token 用量、成本估算、工具调用日志（§6.3）"
 * )
 */
class AgentStatsController extends Controller
{
    public function __construct(
        private AgentMonitorContract $monitor,
        private AgentServiceContract $agentService,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * @OA\Get(
     *     path="/v1/agents/{agentId}/stats",
     *     summary="获取 Agent 使用统计",
     *     description="返回指定时间范围内的会话数、工具调用数、平均响应时间、成功率等指标。",
     *     tags={"Agent 监控"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="start_date", in="query", description="开始日期（Y-m-d）", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", description="结束日期（Y-m-d）", @OA\Schema(type="string", format="date")),
     *
     *     @OA\Response(response=200, description="使用统计", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object")
     *     )),
     *
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function stats(Request $request, int $agentId): JsonResponse
    {
        $this->validateAgentOwnership($agentId);

        $startDate = $request->query('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = $request->query('end_date', date('Y-m-d'));

        $metrics = $this->monitor->getPerformanceMetrics($agentId, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/agents/{agentId}/token-usage",
     *     summary="获取 Agent Token 用量统计",
     *     description="返回指定时间范围内的 prompt_tokens、completion_tokens、total_tokens 汇总。",
     *     tags={"Agent 监控"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="start_date", in="query", description="开始日期（Y-m-d）", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", description="结束日期（Y-m-d）", @OA\Schema(type="string", format="date")),
     *
     *     @OA\Response(response=200, description="Token 用量", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object")
     *     )),
     *
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function tokenUsage(Request $request, int $agentId): JsonResponse
    {
        $this->validateAgentOwnership($agentId);

        $startDate = $request->query('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = $request->query('end_date', date('Y-m-d'));

        $usage = $this->monitor->getTokenUsage($agentId, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => $usage,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/agents/{agentId}/cost",
     *     summary="获取 Agent 成本估算",
     *     description="根据 Token 用量和模型定价估算指定时间范围内的成本。",
     *     tags={"Agent 监控"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="start_date", in="query", description="开始日期（Y-m-d）", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", description="结束日期（Y-m-d）", @OA\Schema(type="string", format="date")),
     *
     *     @OA\Response(response=200, description="成本估算", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object",
     *             @OA\Property(property="agent_id", type="integer"),
     *             @OA\Property(property="start_date", type="string", format="date"),
     *             @OA\Property(property="end_date", type="string", format="date"),
     *             @OA\Property(property="estimated_cost", type="number")
     *         )
     *     )),
     *
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function cost(Request $request, int $agentId): JsonResponse
    {
        $this->validateAgentOwnership($agentId);

        $startDate = $request->query('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = $request->query('end_date', date('Y-m-d'));

        $cost = $this->monitor->getCostEstimate($agentId, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => [
                'agent_id' => $agentId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'estimated_cost' => $cost,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/agents/{agentId}/tool-logs",
     *     summary="获取 Agent 工具调用日志",
     *     description="返回分页的工具调用日志列表，按创建时间倒序排列。",
     *     tags={"Agent 监控"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="page", in="query", description="页码", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="per_page", in="query", description="每页数量（最大 100）", @OA\Schema(type="integer", default=20)),
     *
     *     @OA\Response(response=200, description="工具调用日志（分页）", @OA\JsonContent(
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
    public function toolLogs(Request $request, int $agentId): JsonResponse
    {
        $tenantId = $this->resolveTenantId();
        $this->validateAgentOwnership($agentId);

        $query = AgentToolLog::where('agent_id', $agentId)
            ->orderBy('created_at', 'desc');

        // 通过 agent_conversations 表关联过滤当前租户的会话
        $query->whereIn('conversation_id', function ($subQuery) use ($agentId, $tenantId) {
            $subQuery->select('conversation_id')
                ->from('agent_conversations')
                ->where('agent_id', $agentId)
                ->where('tenant_id', $tenantId);
        });

        $perPage = min((int) $request->query('per_page', 20), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ToolLogResource::collection($logs),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * 校验 Agent 存在且属于当前租户
     */
    private function validateAgentOwnership(int $agentId): void
    {
        $tenantId = $this->resolveTenantId();
        $agent = $this->agentService->find($agentId);

        if ($agent === null || (int) $agent->tenant_id !== $tenantId) {
            abort(404, 'Agent 不存在或不属于当前租户');
        }
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
}
