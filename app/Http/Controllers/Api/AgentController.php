<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\CloneTemplateRequest;
use App\Http\Requests\Agent\CreateAgentRequest;
use App\Http\Requests\Agent\UpdateAgentRequest;
use App\Http\Requests\Agent\UpdateKnowledgeBasesRequest;
use App\Http\Requests\Agent\UpdateModelConfigRequest;
use App\Http\Requests\Agent\UpdateToolsRequest;
use App\Http\Resources\AgentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;

/**
 * @OA\Tag(
 *     name="Agent 管理",
 *     description="Agent 的 CRUD、启用/禁用、预置模板、模型配置、工具与知识库管理（§6.1）"
 * )
 */
class AgentController extends Controller
{
    public function __construct(
        private AgentServiceContract $agentService,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * @OA\Get(
     *     path="/v1/agents",
     *     summary="获取当前租户的所有 Agent",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Agent 列表", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=403, description="无法识别当前租户")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $agents = $this->agentService->listForTenant($tenantId);

        return response()->json([
            'success' => true,
            'data' => AgentResource::collection($agents),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/agents/{agentId}",
     *     summary="获取 Agent 详情",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Agent 详情", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function show(Request $request, int $agentId): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $agent = $this->agentService->find($agentId);

        if ($agent === null || (int) $agent->tenant_id !== $tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Agent 不存在或不属于当前租户',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new AgentResource($agent),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/v1/agents",
     *     summary="创建 Agent",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name","role","system_prompt"},
     *         @OA\Property(property="name", type="string", maxLength=100, example="客服助手"),
     *         @OA\Property(property="role", type="string", maxLength=50, example="customer_service"),
     *         @OA\Property(property="avatar", type="string", maxLength=500, nullable=true),
     *         @OA\Property(property="system_prompt", type="string", example="你是一个专业的客服助手"),
     *         @OA\Property(property="description", type="string", nullable=true),
     *         @OA\Property(property="tools", type="array", @OA\Items(type="string"), nullable=true),
     *         @OA\Property(property="kb_ids", type="array", @OA\Items(type="integer"), nullable=true),
     *         @OA\Property(property="feature_keys", type="array", @OA\Items(type="string"), nullable=true),
     *         @OA\Property(property="model_config", type="object", nullable=true,
     *             @OA\Property(property="preferred_provider", type="string"),
     *             @OA\Property(property="preferred_model", type="string"),
     *             @OA\Property(property="fallback_provider", type="string"),
     *             @OA\Property(property="fallback_model", type="string"),
     *             @OA\Property(property="temperature", type="number", minimum=0, maximum=2),
     *             @OA\Property(property="max_tokens", type="integer", minimum=1),
     *             @OA\Property(property="max_tool_calls", type="integer", minimum=1),
     *             @OA\Property(property="stream", type="boolean")
     *         ),
     *         @OA\Property(property="enabled", type="boolean", nullable=true, default=true),
     *         @OA\Property(property="metadata", type="object", nullable=true)
     *     )),
     *     @OA\Response(response=201, description="创建成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Agent 创建成功"),
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function store(CreateAgentRequest $request): JsonResponse
    {
        $agent = $this->agentService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Agent 创建成功',
            'data' => new AgentResource($agent),
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/agents/{agentId}",
     *     summary="更新 Agent",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="name", type="string", maxLength=100),
     *         @OA\Property(property="role", type="string", maxLength=50),
     *         @OA\Property(property="avatar", type="string", maxLength=500, nullable=true),
     *         @OA\Property(property="system_prompt", type="string"),
     *         @OA\Property(property="description", type="string", nullable=true),
     *         @OA\Property(property="tools", type="array", @OA\Items(type="string"), nullable=true),
     *         @OA\Property(property="kb_ids", type="array", @OA\Items(type="integer"), nullable=true),
     *         @OA\Property(property="feature_keys", type="array", @OA\Items(type="string"), nullable=true),
     *         @OA\Property(property="model_config", type="object", nullable=true),
     *         @OA\Property(property="enabled", type="boolean", nullable=true),
     *         @OA\Property(property="metadata", type="object", nullable=true)
     *     )),
     *     @OA\Response(response=200, description="更新成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Agent 更新成功"),
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function update(UpdateAgentRequest $request, int $agentId): JsonResponse
    {
        try {
            $agent = $this->agentService->update($agentId, $request->validated());
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent 更新成功',
            'data' => new AgentResource($agent),
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/v1/agents/{agentId}",
     *     summary="删除 Agent",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="删除成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Agent 删除成功")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function destroy(Request $request, int $agentId): JsonResponse
    {
        try {
            $this->agentService->delete($agentId);
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent 删除成功',
        ]);
    }

    /**
     * @OA\Post(
     *     path="/v1/agents/{agentId}/enable",
     *     summary="启用 Agent",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="启用成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Agent 已启用")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function enable(Request $request, int $agentId): JsonResponse
    {
        try {
            $this->agentService->enable($agentId);
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent 已启用',
        ]);
    }

    /**
     * @OA\Post(
     *     path="/v1/agents/{agentId}/disable",
     *     summary="禁用 Agent",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="禁用成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Agent 已禁用")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户")
     * )
     */
    public function disable(Request $request, int $agentId): JsonResponse
    {
        try {
            $this->agentService->disable($agentId);
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent 已禁用',
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/agents/templates",
     *     summary="获取预置模板列表",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="模板列表", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )),
     *     @OA\Response(response=401, description="未认证")
     * )
     */
    public function templates(Request $request): JsonResponse
    {
        $templates = $this->agentService->getBuiltinTemplates();

        return response()->json([
            'success' => true,
            'data' => $templates->values(),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/v1/agents/templates/{templateId}/clone",
     *     summary="从预置模板克隆 Agent",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="templateId", in="path", required=true, description="模板 ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="name", type="string", maxLength=100, description="克隆后的 Agent 名称"),
     *         @OA\Property(property="description", type="string", nullable=true),
     *         @OA\Property(property="system_prompt", type="string"),
     *         @OA\Property(property="tools", type="array", @OA\Items(type="string"), nullable=true),
     *         @OA\Property(property="model_config", type="object", nullable=true)
     *     )),
     *     @OA\Response(response=201, description="克隆成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="Agent 从模板克隆成功"),
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="模板不存在"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function cloneTemplate(CloneTemplateRequest $request, int $templateId): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        try {
            $agent = $this->agentService->cloneFromTemplate($templateId, $tenantId, $request->validated());
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent 从模板克隆成功',
            'data' => new AgentResource($agent),
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/agents/{agentId}/model-config",
     *     summary="更新 Agent 的模型配置",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="model_config", type="object",
     *             @OA\Property(property="preferred_provider", type="string"),
     *             @OA\Property(property="preferred_model", type="string"),
     *             @OA\Property(property="fallback_provider", type="string"),
     *             @OA\Property(property="fallback_model", type="string"),
     *             @OA\Property(property="temperature", type="number", minimum=0, maximum=2),
     *             @OA\Property(property="max_tokens", type="integer", minimum=1),
     *             @OA\Property(property="max_tool_calls", type="integer", minimum=1),
     *             @OA\Property(property="stream", type="boolean")
     *         )
     *     )),
     *     @OA\Response(response=200, description="更新成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="模型配置更新成功")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户"),
     *     @OA\Response(response=422, description="参数校验失败（如 temperature > 2）")
     * )
     */
    public function updateModelConfig(UpdateModelConfigRequest $request, int $agentId): JsonResponse
    {
        try {
            $this->agentService->updateModelConfig($agentId, $request->validated());
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => '模型配置更新成功',
        ]);
    }

    /**
     * @OA\Put(
     *     path="/v1/agents/{agentId}/tools",
     *     summary="更新 Agent 绑定的工具",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"tool_slugs"},
     *         @OA\Property(property="tool_slugs", type="array", @OA\Items(type="string"), description="工具 slug 列表")
     *     )),
     *     @OA\Response(response=200, description="更新成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="工具配置更新成功")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function updateTools(UpdateToolsRequest $request, int $agentId): JsonResponse
    {
        try {
            $this->agentService->attachTools($agentId, $request->validated('tool_slugs'));
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => '工具配置更新成功',
        ]);
    }

    /**
     * @OA\Put(
     *     path="/v1/agents/{agentId}/knowledge-bases",
     *     summary="更新 Agent 绑定的知识库",
     *     tags={"Agent 管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="agentId", in="path", required=true, description="Agent ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"kb_ids"},
     *         @OA\Property(property="kb_ids", type="array", @OA\Items(type="integer"), description="知识库 ID 列表")
     *     )),
     *     @OA\Response(response=200, description="更新成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="知识库配置更新成功")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="Agent 不存在或不属于当前租户"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function updateKnowledgeBases(UpdateKnowledgeBasesRequest $request, int $agentId): JsonResponse
    {
        try {
            $this->agentService->attachKnowledgeBases($agentId, $request->validated('kb_ids'));
        } catch (\Exception $e) {
            return $this->handleServiceException($e);
        }

        return response()->json([
            'success' => true,
            'message' => '知识库配置更新成功',
        ]);
    }

    /**
     * 统一处理服务层异常
     */
    private function handleServiceException(\Exception $e): JsonResponse
    {
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Agent 不存在或不属于当前租户',
            ], 404);
        }

        if ($e instanceof \InvalidArgumentException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }

        return response()->json([
            'success' => false,
            'message' => '操作失败',
        ], 500);
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
