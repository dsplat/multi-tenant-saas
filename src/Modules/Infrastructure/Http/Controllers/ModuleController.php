<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleRegistry;

class ModuleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ModuleManager $manager,
        protected ModuleRegistry $registry,
    ) {}

    // ========== 系统管理 (admin 域) ==========

    /**
     * 列出所有模块 (系统级)
     */
    public function index(): JsonResponse
    {
        return $this->successResponse($this->manager->listAll());
    }

    /**
     * 系统级启用模块
     */
    public function enable(string $name): JsonResponse
    {
        if (! $this->registry->has($name)) {
            return $this->notFoundResponse("模块 [{$name}] 不存在");
        }

        if ($this->manager->isEnabled($name)) {
            return $this->successResponse(null, "模块 [{$name}] 已是启用状态");
        }

        $this->manager->enable($name);

        return $this->successResponse(null, "模块 [{$name}] 已启用");
    }

    /**
     * 系统级禁用模块
     */
    public function disable(string $name): JsonResponse
    {
        if (! $this->registry->has($name)) {
            return $this->notFoundResponse("模块 [{$name}] 不存在");
        }

        if (! $this->manager->isEnabled($name)) {
            return $this->successResponse(null, "模块 [{$name}] 已是禁用状态");
        }

        $this->manager->disable($name);

        return $this->successResponse(null, "模块 [{$name}] 已禁用");
    }

    // ========== 租户管理 ==========

    /**
     * 列出租户可用模块
     */
    public function tenantIndex(int $tenantId): JsonResponse
    {
        $modules = $this->manager->listForTenant($tenantId);

        return $this->successResponse($modules);
    }

    /**
     * 为租户启用模块
     */
    public function tenantEnable(int $tenantId, string $name): JsonResponse
    {
        if (! $this->registry->has($name)) {
            return $this->notFoundResponse("模块 [{$name}] 不存在");
        }

        if (! $this->registry->tenantToggleable($name)) {
            return $this->errorResponse("模块 [{$name}] 不支持租户级切换", 422);
        }

        if ($this->manager->isEnabledForTenant($name, $tenantId)) {
            return $this->successResponse(null, "模块 [{$name}] 已为该租户启用");
        }

        try {
            $this->manager->enableForTenant($name, $tenantId);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }

        return $this->successResponse(null, "模块 [{$name}] 已为租户启用");
    }

    /**
     * 为租户禁用模块
     */
    public function tenantDisable(int $tenantId, string $name): JsonResponse
    {
        if (! $this->registry->has($name)) {
            return $this->notFoundResponse("模块 [{$name}] 不存在");
        }

        if (! $this->registry->tenantToggleable($name)) {
            return $this->errorResponse("模块 [{$name}] 不支持租户级切换", 422);
        }

        if (! $this->manager->isEnabledForTenant($name, $tenantId)) {
            return $this->successResponse(null, "模块 [{$name}] 已为该租户禁用");
        }

        try {
            $this->manager->disableForTenant($name, $tenantId);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }

        return $this->successResponse(null, "模块 [{$name}] 已为租户禁用");
    }
}
