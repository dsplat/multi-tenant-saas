<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\RbacService;
use MultiTenantSaas\Services\AuditService;

class RbacController extends Controller
{
    /**
     * 获取权限列表（按分组）
     */
    public function permissions(Request $request)
    {
        $grouped = RbacService::getAllPermissionsGrouped();
        return response()->json(['data' => $grouped]);
    }

    /**
     * 获取角色列表
     */
    public function roles(Request $request, int $tenantId)
    {
        $roles = RbacService::getTenantRoles($tenantId);
        return response()->json(['data' => $roles]);
    }

    /**
     * 创建自定义角色
     */
    public function storeRole(Request $request, int $tenantId)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'display_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'permission_ids' => 'array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        $role = RbacService::createRole(
            $tenantId,
            $validated['name'],
            $validated['display_name'],
            $validated['description'] ?? null,
            $validated['permission_ids'] ?? []
        );

        AuditService::log('create', 'role', $role->id, "创建角色: {$role->display_name}");

        return response()->json(['data' => $role->load('permissions')], 201);
    }

    /**
     * 更新角色权限
     */
    public function updateRolePermissions(Request $request, int $tenantId, int $roleId)
    {
        $validated = $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        try {
            RbacService::updateRolePermissions($roleId, $validated['permission_ids']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditService::log('update', 'role', $roleId, '更新角色权限');

        return response()->json(['message' => '角色权限已更新']);
    }

    /**
     * 删除自定义角色
     */
    public function destroyRole(Request $request, int $tenantId, int $roleId)
    {
        try {
            RbacService::deleteRole($roleId);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditService::log('delete', 'role', $roleId, '删除角色');

        return response()->json(['message' => '角色已删除']);
    }

    /**
     * 为成员分配角色
     */
    public function assignMemberRole(Request $request, int $tenantId, int $userId)
    {
        $validated = $request->validate([
            'role_id' => 'required|integer|exists:roles,id',
        ]);

        \DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->update(['role_id' => $validated['role_id']]);

        AuditService::log('update', 'tenant_member', $userId, "分配角色ID: {$validated['role_id']}");

        return response()->json(['message' => '角色已分配']);
    }
}
