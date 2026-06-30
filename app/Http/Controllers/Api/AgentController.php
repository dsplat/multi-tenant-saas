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
 * Agent 管理 API（§6.1）
 *
 * 提供 Agent 的 CRUD、启用/禁用、预置模板、模型配置、工具与知识库管理端点。
 * 所有操作强制租户隔离，tenant_id 从 TenantContext 解析（认证中间件设置）。
 *
 * 端点：
 *  GET    /api/v1/agents                      index
 *  GET    /api/v1/agents/{id}                 show
 *  POST   /api/v1/agents                      store
 *  PUT    /api/v1/agents/{id}                 update
 *  DELETE /api/v1/agents/{id}                 destroy
 *  POST   /api/v1/agents/{id}/enable          enable
 *  POST   /api/v1/agents/{id}/disable         disable
 *  GET    /api/v1/agents/templates            templates
 *  POST   /api/v1/agents/templates/{id}/clone cloneTemplate
 *  PUT    /api/v1/agents/{id}/model-config    updateModelConfig
 *  PUT    /api/v1/agents/{id}/tools           updateTools
 *  PUT    /api/v1/agents/{id}/knowledge-bases updateKnowledgeBases
 */
class AgentController extends Controller
{
    public function __construct(
        private AgentServiceContract $agentService,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * 获取当前租户的所有 Agent
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
     * 获取 Agent 详情
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
     * 创建 Agent
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
     * 更新 Agent
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
     * 删除 Agent
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
     * 启用 Agent
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
     * 禁用 Agent
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
     * 获取预置模板列表
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
     * 从预置模板克隆 Agent
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
     * 更新 Agent 的模型配置
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
     * 更新 Agent 绑定的工具
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
     * 更新 Agent 绑定的知识库
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
