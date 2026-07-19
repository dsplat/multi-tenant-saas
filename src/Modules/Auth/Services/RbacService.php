<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\Permission;
use MultiTenantSaas\Modules\Auth\Models\Role;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;

/**
 * RBAC 服务（Operator 直连租户模式）
 *
 * 设计原则：
 * - Operator 直接通过 operator_tenants 关联到团队，取 role_id 查权限
 * - User 是租户开放注册后的业务用户，走 tenant_users 路径查权限
 * - 两条路径独立，不再交叉
 */
class RbacService
{
    private const CACHE_PREFIX = 'rbac:permissions:';

    private const CACHE_TTL = 3600;

    /**
     * 检查当前用户（Operator 或 User）是否拥有指定权限
     */
    public static function check(string $permission): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $tenantId = TenantContext::getId() ?? request()->route('tenantId');

        // 1) Operator 直连路径
        if ($user instanceof Operator) {
            return static::checkOperatorPermission($user, $tenantId, $permission);
        }

        // 2) User 路径（租户开放注册后的业务用户，走 tenant_users）
        if ($tenantId) {
            $tenantUser = $user->tenants()
                ->wherePivot('is_active', true)
                ->where('tenants.tenant_id', $tenantId)
                ->first();
        } else {
            $tenantUser = $user->tenants()
                ->wherePivot('is_active', true)
                ->first();
        }

        if (! $tenantUser || ! $tenantUser->pivot->role_id) {
            return false;
        }

        return static::checkRolePermission($tenantUser->pivot->role_id, $permission);
    }

    /**
     * 检查 Operator 在指定团队（租户）中是否拥有指定权限
     */
    protected static function checkOperatorPermission(Operator $operator, ?int $tenantId, string $permission): bool
    {
        if (! $tenantId) {
            return false;
        }

        $operatorTenant = OperatorTenant::where('operator_id', $operator->operator_id)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (! $operatorTenant || ! $operatorTenant->role_id) {
            return false;
        }

        return static::checkRolePermission($operatorTenant->role_id, $permission);
    }

    public static function checkRolePermission(int $roleId, string $permission): bool
    {
        $permissions = static::getRolePermissions($roleId);

        return in_array($permission, $permissions);
    }

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
     * 获取当前用户（Operator 或 User）的所有权限标识
     */
    public static function getCurrentUserPermissions(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $tenantId = TenantContext::getId() ?? request()->route('tenantId');

        if ($user instanceof Operator) {
            $operatorTenant = OperatorTenant::where('operator_id', $user->operator_id)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();

            if ($operatorTenant && $operatorTenant->role_id) {
                return static::getRolePermissions($operatorTenant->role_id);
            }

            return [];
        }

        // User 路径
        if ($tenantId) {
            $tenantUser = $user->tenants()
                ->wherePivot('is_active', true)
                ->where('tenants.tenant_id', $tenantId)
                ->first();
        } else {
            $tenantUser = $user->tenants()
                ->wherePivot('is_active', true)
                ->first();
        }

        return $tenantUser && $tenantUser->pivot->role_id
            ? static::getRolePermissions($tenantUser->pivot->role_id)
            : [];
    }

    public static function clearRoleCache(int $roleId): void
    {
        Cache::forget(self::CACHE_PREFIX . $roleId);
    }

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

    public static function updateRolePermissions(int $roleId, array $permissionIds): void
    {
        $role = Role::findOrFail($roleId);

        if ($role->is_system) {
            throw new \RuntimeException(trans('tenant.system_role_protected'));
        }

        $role->permissions()->sync($permissionIds);
        static::clearRoleCache($roleId);
    }

    public static function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        if ($role->is_system) {
            throw new \RuntimeException(trans('tenant.system_role_no_delete'));
        }

        DB::table('tenant_users')
            ->where('role_id', $roleId)
            ->update(['role_id' => null]);

        $role->delete();
    }

    public static function getAllPermissionsGrouped(): array
    {
        return Permission::orderBy('group')->orderBy('name')
            ->get()
            ->groupBy('group')
            ->toArray();
    }

    public static function getTenantRoles(int $tenantId): Collection
    {
        return Role::where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        })->with('permissions:permission_id,name,display_name,group')->get();
    }
}
