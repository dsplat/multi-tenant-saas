<?php

namespace MultiTenantSaas\Modules\Storage\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Storage\Services\StorageConfigService;

/**
 * 租户对象存储配置（console 设置页）
 */
class StorageConfigController extends Controller
{
    public function __construct(protected StorageConfigService $service) {}

    /**
     * 当前配置 + 生效来源（租户/平台/系统兜底）
     */
    public function show(Request $request)
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data' => [
                'config' => $this->service->getTenantConfig($tenantId),
                'status' => $this->service->resolveStatus($tenantId),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'driver' => 'sometimes|string|in:s3',
            'endpoint' => 'nullable|url|max:500',
            'bucket' => 'nullable|string|max:200',
            'region' => 'nullable|string|max:100',
            'access_key_id' => 'nullable|string|max:255',
            'access_key_secret' => 'nullable|string|max:255',
            'url' => 'nullable|url|max:500',
            'use_path_style' => 'sometimes|boolean',
        ]);

        $tenantId = (int) TenantContext::getId();
        $this->service->updateTenantConfig($tenantId, $data);

        return response()->json([
            'success' => true,
            'data' => [
                'config' => $this->service->getTenantConfig($tenantId),
                'status' => $this->service->resolveStatus($tenantId),
            ],
        ]);
    }
}
