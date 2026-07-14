<?php

namespace MultiTenantSaas\Modules\Operator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\Operator\Services\OperatorService;

class OperatorController extends Controller
{
    private const ROLES_CACHE_KEY = 'operator:valid_roles';

    private const ROLES_CACHE_TTL = 3600; // 1 hour

    public function __construct(
        protected OperatorService $operatorService,
    ) {}

    /**
     * 列出租户的运营人员
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $operators = $this->operatorService->listByTenant($tenantId);

        return response()->json([
            'success' => true,
            'data' => $operators,
        ]);
    }

    /**
     * 获取有效角色列表（缓存）
     */
    private function getValidRoles(): array
    {
        return Cache::remember(self::ROLES_CACHE_KEY, self::ROLES_CACHE_TTL, function () {
            return DB::table('roles')->pluck('name')->toArray();
        });
    }

    /**
     * 邀请运营人员
     */
    public function invite(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'role' => 'required|string|in:' . implode(',', $this->getValidRoles()),
        ]);

        $tenantId = TenantContext::getId();
        $result = $this->operatorService->invite(
            $request->email,
            $tenantId,
            $request->role
        );

        AuditService::log('invite', 'operator', $result['operator_id'] ?? null, null, [
            'email' => $request->email,
            'tenant_id' => $tenantId,
            'role' => $request->role,
        ]);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => trans('operator.invite_sent'),
        ], 201);
    }

    /**
     * 接受邀请（公开路由）
     */
    public function acceptInvite(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $result = $this->operatorService->acceptInvite(
            $request->token,
            $request->password
        );

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => trans('operator.invalid_invite'),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => trans('operator.account_activated'),
        ]);
    }

    /**
     * 更新运营人员角色
     */
    public function updateRole(Request $request, int $operatorId): JsonResponse
    {
        $request->validate([
            'role' => 'required|string|in:' . implode(',', $this->getValidRoles()),
        ]);

        $tenantId = TenantContext::getId();
        $this->operatorService->updateRole($operatorId, $tenantId, $request->role);

        AuditService::log('update', 'operator', $operatorId, null, [
            'tenant_id' => $tenantId,
            'new_role' => $request->role,
        ]);

        return response()->json([
            'success' => true,
            'message' => trans('operator.role_updated'),
        ]);
    }

    /**
     * 移除运营人员
     */
    public function remove(Request $request, int $operatorId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->operatorService->removeFromTenant($operatorId, $tenantId);

        AuditService::log('remove', 'operator', $operatorId, null, [
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => trans('operator.removed'),
        ]);
    }

    /**
     * 列出运营人员管理的租户
     */
    public function tenants(Request $request, int $operatorId): JsonResponse
    {
        $tenants = $this->operatorService->listTenants($operatorId);

        return response()->json([
            'success' => true,
            'data' => $tenants,
        ]);
    }
}
