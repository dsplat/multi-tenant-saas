<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Models\McpClient;
use MultiTenantSaas\Services\RbacService;

class McpClientController extends Controller
{
    /**
     * 列出当前租户的所有 MCP 客户端
     */
    public function index(Request $request): JsonResponse
    {
        RbacService::check('mcp_client.view');

        $clients = McpClient::query()
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
    public function show(Request $request, int $id): JsonResponse
    {
        RbacService::check('mcp_client.view');

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
        RbacService::check('mcp_client.create');

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
        RbacService::check('mcp_client.update');

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
    public function destroy(Request $request, int $id): JsonResponse
    {
        RbacService::check('mcp_client.delete');

        $client = McpClient::findOrFail($id);
        $client->delete();

        return response()->json([
            'success' => true,
            'message' => trans('common.deleted'),
        ]);
    }
}