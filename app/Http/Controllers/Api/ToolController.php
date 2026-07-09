<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\RegisterToolRequest;
use App\Http\Requests\Agent\UpdateToolRequest;
use App\Http\Resources\ToolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\AgentTool;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * @OA\Tag(
 *     name="工具管理",
 *     description="工具的列表、详情、注册、更新、删除。工具分两级：全局工具（tenant_id=0）和租户私有工具（§6.4）"
 * )
 */
class ToolController extends Controller
{
    public function __construct(
        private ToolRegistryContract $toolRegistry,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * @OA\Get(
     *     path="/v1/tools",
     *     summary="获取当前租户可用的所有工具",
     *     description="包含全局工具（tenant_id=0）和当前租户私有工具。",
     *     tags={"工具管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="工具列表", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )),
     *     @OA\Response(response=401, description="未认证")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $tools = AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('enabled', true)
            ->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                    ->orWhere('tenant_id', 0);
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ToolResource::collection($tools),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/tools/{slug}",
     *     summary="获取指定工具详情",
     *     description="按 slug 查询，返回当前租户可见的工具（全局或私有）。",
     *     tags={"工具管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="slug", in="path", required=true, description="工具 slug", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="工具详情", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="工具不存在或不属于当前租户")
     * )
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $tool = AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                    ->orWhere('tenant_id', 0);
            })
            ->first();

        if ($tool === null) {
            return response()->json([
                'success' => false,
                'message' => '工具不存在或不属于当前租户',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ToolResource($tool),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/v1/tools",
     *     summary="注册新工具",
     *     description="同时写入数据库（持久化）和注册表（运行时可用）。",
     *     tags={"工具管理"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name","slug","description","parameters_schema","handler_class"},
     *         @OA\Property(property="name", type="string", maxLength=100, example="订单查询工具"),
     *         @OA\Property(property="slug", type="string", maxLength=100, example="order-search"),
     *         @OA\Property(property="description", type="string", example="根据条件查询订单"),
     *         @OA\Property(property="category", type="string", maxLength=50, nullable=true, example="ecommerce"),
     *         @OA\Property(property="parameters_schema", type="object", description="JSON Schema 格式的参数定义"),
     *         @OA\Property(property="handler_class", type="string", maxLength=255, example="App\\Handlers\\OrderSearchHandler"),
     *         @OA\Property(property="enabled", type="boolean", nullable=true, default=true)
     *     )),
     *     @OA\Response(response=201, description="注册成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="工具注册成功"),
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function store(RegisterToolRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $tool = AgentTool::create([
            'tenant_id' => $tenantId,
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug'),
            'description' => $request->validated('description'),
            'category' => $request->input('category'),
            'parameters_schema' => $request->validated('parameters_schema'),
            'handler_class' => $request->validated('handler_class'),
            'enabled' => $request->boolean('enabled', true),
        ]);

        // 同步注册到运行时注册表
        $this->toolRegistry->register(
            $tool->slug,
            $tool->name,
            $tool->description,
            $tool->handler_class,
            $tool->parameters_schema,
            $tool->category ?? 'core'
        );

        return response()->json([
            'success' => true,
            'message' => '工具注册成功',
            'data' => new ToolResource($tool),
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/v1/tools/{slug}",
     *     summary="更新工具",
     *     description="仅允许更新当前租户私有的工具，全局工具不可修改。",
     *     tags={"工具管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="slug", in="path", required=true, description="工具 slug", @OA\Schema(type="string")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="name", type="string", maxLength=100),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="category", type="string", maxLength=50, nullable=true),
     *         @OA\Property(property="parameters_schema", type="object", nullable=true),
     *         @OA\Property(property="handler_class", type="string", maxLength=255, nullable=true),
     *         @OA\Property(property="enabled", type="boolean", nullable=true)
     *     )),
     *     @OA\Response(response=200, description="更新成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="工具更新成功"),
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="工具不存在或不属于当前租户"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function update(UpdateToolRequest $request, string $slug): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $tool = AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('tenant_id', $tenantId) // 仅限租户私有工具
            ->first();

        if ($tool === null) {
            return response()->json([
                'success' => false,
                'message' => '工具不存在或不属于当前租户',
            ], 404);
        }

        $tool->update(array_filter([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'category' => $request->input('category'),
            'parameters_schema' => $request->input('parameters_schema'),
            'handler_class' => $request->input('handler_class'),
            'enabled' => $request->input('enabled'),
        ], fn ($value) => $value !== null));

        return response()->json([
            'success' => true,
            'message' => '工具更新成功',
            'data' => new ToolResource($tool->fresh()),
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/v1/tools/{slug}",
     *     summary="删除工具",
     *     description="仅允许删除当前租户私有的工具，全局工具不可删除。",
     *     tags={"工具管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="slug", in="path", required=true, description="工具 slug", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="删除成功", @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="工具已删除")
     *     )),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=404, description="工具不存在或不属于当前租户")
     * )
     */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $tool = AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('tenant_id', $tenantId) // 仅限租户私有工具
            ->first();

        if ($tool === null) {
            return response()->json([
                'success' => false,
                'message' => '工具不存在或不属于当前租户',
            ], 404);
        }

        $tool->delete();

        return response()->json([
            'success' => true,
            'message' => '工具已删除',
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
}
