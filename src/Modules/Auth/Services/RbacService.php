<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\Permission;
use MultiTenantSaas\Modules\Auth\Models\Role;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;

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

        $tenantId = TenantContext::getId() ?? request()->route('tenantId');
        if (! $tenantId) {
            return false;
        }

        // 优先通过 operator_tenants 查找角色
        $operatorTenant = OperatorTenant::where('user_id', $user->user_id)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if ($operatorTenant && $operatorTenant->role_id) {
            return static::checkRolePermission($operatorTenant->role_id, $permission);
        }

        // 回退到 tenant_users 路径（向后兼容）
        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $tenantUser || ! $tenantUser->pivot->role_id) {
            return false;
        }

        return static::checkRolePermission($tenantUser->pivot->role_id, $permission);
    }

    /**
     * 检查角色是否拥有指定权限
     */
    public static function checkRolePermission(int $roleId, string $permission): bool
    {
        $permissions = static::getRolePermissions($roleId);

        return in_array($permission, $permissions);
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
