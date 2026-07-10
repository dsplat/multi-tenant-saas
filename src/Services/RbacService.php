<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Permission;
use MultiTenantSaas\Models\Role;

class RbacService
{
    private const CACHE_PREFIX = 'rbac:permissions:';

    private const CACHE_TTL = 3600;

    /**
     * 检查当前用户是否拥有指定权限
     */
    public static function check(string $permission): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // super_admin 直接放行
        if ($user->role === 'super_admin') {
            return true;
        }

        $tenantId = TenantContext::getId() ?? request()->route('tenantId');
        if (! $tenantId) {
            return false;
        }

        // 获取用户在当前租户的角色
        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $tenantUser) {
            return false;
        }

        return static::checkRolePermission($tenantUser->pivot->role_id, $tenantUser->pivot->role, $permission);
    }

    /**
     * 检查角色是否拥有指定权限
     */
    public static function checkRolePermission(?int $roleId, ?string $roleName, string $permission): bool
    {
        // 如果有 role_id，走动态权限检查
        if ($roleId) {
            $permissions = static::getRolePermissions($roleId);

            return in_array($permission, $permissions);
        }

        // 回退到字符串角色兼容模式
        return static::checkByRoleName($roleName, $permission);
    }

    /**
     * 获取角色的所有权限标识
     */
    public static function getRolePermissions(int $roleId): array
    {
        return Cache::remember(
            self::CACHE_PREFIX . $roleId,
            self::CACHE_TTL,
            fn () => DB::table('role_permissions')
                ->join('permissions', 'permissions.permission_id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_id', $roleId)
                ->pluck('permissions.name')
                ->toArray()
        );
    }

    /**
     * 字符串角色兼容模式
     */
    protected static function checkByRoleName(?string $roleName, string $permission): bool
    {
        if (! $roleName) {
            return false;
        }

        // tenant_admin 默认拥有除平台级之外的所有权限
        if ($roleName === 'tenant_admin') {
            $denied = ['tenant.create', 'tenant.delete', 'tenant.suspend'];

            return ! in_array($permission, $denied);
        }

        // end_user 默认只有查看权限
        if ($roleName === 'end_user') {
            $allowed = ['tenant.view', 'member.view', 'credit.view', 'setting.view', 'payment.view', 'audit.view', 'file.upload'];

            return in_array($permission, $allowed);
        }

        return false;
    }

    /**
     * 清除角色权限缓存
     */
    public static function clearRoleCache(int $roleId): void
    {
        Cache::forget(self::CACHE_PREFIX . $roleId);
    }

    /**
     * 创建自定义角色
     */
    public static function createRole(int $tenantId, string $name, string $displayName, ?string $description = null, array $permissionIds = []): Role
    {
        $role = Role::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'display_name' => $displayName,
            'description' => $description,
            'is_system' => false,
        ]);

        if (! empty($permissionIds)) {
            $role->permissions()->sync($permissionIds);
        }

        return $role;
    }

    /**
     * 更新角色权限
     */
    public static function updateRolePermissions(int $roleId, array $permissionIds): void
    {
        $role = Role::findOrFail($roleId);

        if ($role->is_system) {
            throw new \RuntimeException(trans('tenant.system_role_protected'));
        }

        $role->permissions()->sync($permissionIds);
        static::clearRoleCache($roleId);
    }

    /**
     * 删除自定义角色
     */
    public static function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        if ($role->is_system) {
            throw new \RuntimeException(trans('tenant.system_role_no_delete'));
        }

        // 解除该角色关联的成员
        DB::table('tenant_users')
            ->where('role_id', $roleId)
            ->update(['role_id' => null]);

        $role->delete();
    }

    /**
     * 获取所有权限列表（按分组）
     */
    public static function getAllPermissionsGrouped(): array
    {
        return Permission::orderBy('group')->orderBy('name')
            ->get()
            ->groupBy('group')
            ->toArray();
    }

    /**
     * 获取租户可用角色（系统级 + 租户级）
     */
    public static function getTenantRoles(int $tenantId): Collection
    {
        return Role::where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        })->with('permissions:permission_id,name,display_name,group')->get();
    }
}
