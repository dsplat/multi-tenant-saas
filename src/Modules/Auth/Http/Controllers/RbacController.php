<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * @OA\Tag(
 *     name="RBAC权限",
 *     description="角色、权限管理和成员角色分配"
 * )
 */
class RbacController extends Controller
{
    /**
     * 获取权限列表（按分组）
     */
    public function permissions(Request $request)
    {
        $grouped = app(RbacService::class)->getAllPermissionsGrouped();

        return response()->json(['success' => true, 'data' => $grouped]);
    }

    /**
     * 获取角色列表
     */
    public function roles(Request $request)
    {
        $tenantId = TenantContext::getId();
        $roles = app(RbacService::class)->getTenantRoles($tenantId);

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /**
     * 创建自定义角色
     */
    public function storeRole(Request $request)
    {
        $tenantId = TenantContext::getId();

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'display_name' => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permissions,permission_id',
        ]);

        $role = app(RbacService::class)->createRole(
            $tenantId,
            $validated['name'],
            $validated['display_name'],
            $validated['description'] ?? null,
            $validated['permission_ids'] ?? []
        );

        app(AuditService::class)->log('create', 'role', $role->role_id, null, [
            'name' => $validated['name'],
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'data' => $role,
            'message' => trans('rbac.role_created'),
        ], 201);
    }

    /**
     * 更新角色权限
     */
    public function updateRolePermissions(Request $request, int $roleId)
    {
        $validated = $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'integer|exists:permissions,permission_id',
        ]);

        try {
            app(RbacService::class)->updateRolePermissions($roleId, $validated['permission_ids']);

            app(AuditService::class)->log('update', 'role', $roleId, null, [
                'permission_ids' => $validated['permission_ids'],
            ]);

            return response()->json([
                'success' => true,
                'message' => trans('rbac.permissions_updated'),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * 删除自定义角色
     */
    public function destroyRole(Request $request, int $roleId)
    {
        try {
            app(RbacService::class)->deleteRole($roleId);

            app(AuditService::class)->log('delete', 'role', $roleId, null, null);

            return response()->json([
                'success' => true,
                'message' => trans('rbac.role_deleted'),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * 分配成员角色
     */
    public function assignMemberRole(Request $request, int $userId)
    {
        $tenantId = TenantContext::getId();

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,role_id',
        ]);

        $tenantUser = \DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (! $tenantUser) {
            return response()->json([
                'success' => false,
                'message' => trans('rbac.member_not_found'),
            ], 404);
        }

        \DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->update(['role_id' => $validated['role_id']]);

        app(AuditService::class)->log('update', 'tenant_user', $userId, [
            'role_id' => $tenantUser->role_id,
        ], [
            'role_id' => $validated['role_id'],
        ]);

        return response()->json([
            'success' => true,
            'message' => trans('rbac.role_assigned'),
        ]);
    }
}
