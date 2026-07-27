<?php

namespace MultiTenantSaas\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Knowledge\Services\ExternalKbService;

/**
 * 租户外部知识库连接管理（console 设置页）
 */
class ExternalKbConnectionController extends Controller
{
    public function __construct(protected ExternalKbService $service) {}

    /**
     * 连接列表 + 当前生效状态（租户/平台回退）
     */
    public function index(Request $request)
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data' => [
                'connections' => $this->service->listConnections($tenantId),
                'status' => $this->service->resolveStatus($tenantId),
                'providers' => array_keys(ExternalKbService::PROVIDERS),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider_type' => 'required|string|in:' . implode(',', array_keys(ExternalKbService::PROVIDERS)),
            'name' => 'required|string|max:100',
            'api_url' => 'required|url|max:500',
            'api_key' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,disabled',
            'config' => 'nullable|array',
        ]);

        $connection = $this->service->createConnection((int) TenantContext::getId(), $data);

        return response()->json([
            'success' => true,
            'data' => $this->service->presentConnection($connection),
        ], 201);
    }

    public function update(Request $request, int $connectionId)
    {
        $data = $request->validate([
            'provider_type' => 'sometimes|string|in:' . implode(',', array_keys(ExternalKbService::PROVIDERS)),
            'name' => 'sometimes|string|max:100',
            'api_url' => 'sometimes|url|max:500',
            'api_key' => 'nullable|string|max:255',
            'status' => 'sometimes|string|in:active,disabled',
            'config' => 'nullable|array',
        ]);

        $connection = $this->service->updateConnection((int) TenantContext::getId(), $connectionId, $data);

        return response()->json([
            'success' => true,
            'data' => $this->service->presentConnection($connection),
        ]);
    }

    public function destroy(int $connectionId)
    {
        $this->service->deleteConnection((int) TenantContext::getId(), $connectionId);

        return response()->json(['success' => true]);
    }

    /**
     * 测试连接可用性
     */
    public function test(int $connectionId)
    {
        $result = $this->service->testConnection((int) TenantContext::getId(), $connectionId);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
    }
}
