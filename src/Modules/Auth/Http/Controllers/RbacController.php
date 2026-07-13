<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
    use AuthorizesTenantAccess;

    /**
     * 获取权限列表（按分组）
     */
    public function permissions(Request $request)
    {
        $grouped = RbacService::getAllPermissionsGrouped();

        return response()->json(['success' => true, 'data' => $grouped]);
    }

    /**
     * 获取角色列表
     */
    public function roles(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $roles = RbacService::getTenantRoles($tenantId);

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /**
     * 创建自定义角色
     */
    public function storeRole(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'display_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'permission_ids' => 'array',
            'permission_ids.*' => 'integer|exists:permissions,permission_id',
        ]);

        $role = RbacService::createRole(
            $tenantId,
            $validated['name'],
            $validated['display_name'],
            $validated['description'] ?? null,
            $validated['permission_ids'] ?? []
        );

        AuditService::log('create', 'role', $role->id, null, ['name' => $role->display_name]);

        return response()->json(['success' => true, 'data' => $role->load('permissions')], 201);
    }

    /**
     * 更新角色权限
     */
    public function updateRolePermissions(Request $request, int $tenantId, int $roleId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'integer|exists:permissions,permission_id',
        ]);

        try {
            RbacService::updateRolePermissions($roleId, $validated['permission_ids']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditService::log('update', 'role', $roleId, null, ['permission_count' => count($validated['permission_ids'])]);

        return response()->json(['success' => true, 'message' => trans('tenant.role_updated')]);
    }

    /**
     * 删除自定义角色
     */
    public function destroyRole(Request $request, int $tenantId, int $roleId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        try {
            RbacService::deleteRole($roleId);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditService::log('delete', 'role', $roleId, null, ['deleted' => true]);

        return response()->json(['success' => true, 'message' => trans('tenant.role_deleted')]);
    }

    /**
     * 为成员分配角色
     */
    public function assignMemberRole(Request $request, int $tenantId, int $userId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'role_id' => 'required|integer|exists:roles,role_id',
        ]);

        \DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->update(['role_id' => $validated['role_id']]);

        AuditService::log('update', 'tenant_member', $userId, null, ['role_id' => $validated['role_id']]);

        return response()->json(['success' => true, 'message' => trans('tenant.role_assigned')]);
    }
}
