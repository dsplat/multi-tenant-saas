<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\McpClient;
use MultiTenantSaas\Services\RbacService;

class McpClientController extends Controller
{
    /**
     * 列出当前租户的所有 MCP 客户端
     */
    public function index(): JsonResponse
    {
        if (!RbacService::check('mcp_client.view')) {
            abort(403);
        }

        $tenantId = TenantContext::getId();

        $clients = McpClient::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('mcp_client_id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $clients,
        ]);
    }

    /**
     * 查看单个 MCP 客户端详情
     */
    public function show(int $id): JsonResponse
    {
        if (!RbacService::check('mcp_client.view')) {
            abort(403);
        }

        $client = McpClient::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $client,
        ]);
    }

    /**
     * 创建 MCP 客户端
     */
    public function store(Request $request): JsonResponse
    {
        if (!RbacService::check('mcp_client.create')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_url' => 'required|string|url|max:2048',
            'api_key' => 'nullable|string|max:4096',
            'status' => 'nullable|string|in:' . implode(',', McpClient::STATUSES),
        ]);

        $client = McpClient::create($validated);

        return response()->json([
            'success' => true,
            'data' => $client,
        ], 201);
    }

    /**
     * 更新 MCP 客户端
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!RbacService::check('mcp_client.update')) {
            abort(403);
        }

        $client = McpClient::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'base_url' => 'sometimes|string|url|max:2048',
            'api_key' => 'nullable|string|max:4096',
            'status' => 'sometimes|string|in:' . implode(',', McpClient::STATUSES),
        ]);

        $client->update($validated);

        return response()->json([
            'success' => true,
            'data' => $client->fresh(),
        ]);
    }

    /**
     * 删除 MCP 客户端
     */
    public function destroy(int $id): JsonResponse
    {
        if (!RbacService::check('mcp_client.delete')) {
            abort(403);
        }

        $client = McpClient::findOrFail($id);
        $client->delete();

        return response()->json([
            'success' => true,
            'message' => trans('common.deleted'),
        ]);
    }
}