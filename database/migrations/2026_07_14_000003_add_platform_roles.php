<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\IdGeneratorContract;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $idGenerator = app(IdGeneratorContract::class);

        // 插入 platform_admin 和 platform_support 角色（如不存在）
        $roles = [
            ['name' => 'platform_admin', 'display_name' => '平台管理员', 'description' => '平台运营管理角色，拥有除租户核心操作外的所有权限'],
            ['name' => 'platform_support', 'display_name' => '平台支持', 'description' => '平台客服支持角色，拥有查看权限及成员管理权限'],
        ];

        foreach ($roles as $role) {
            $exists = DB::table('roles')
                ->where('tenant_id', null)
                ->where('name', $role['name'])
                ->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'role_id' => $idGenerator->generate(),
                    'tenant_id' => null,
                    'name' => $role['name'],
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // platform_admin: 所有权限，除 tenant.create, tenant.delete, tenant.suspend
        $adminRoleId = DB::table('roles')
            ->where('name', 'platform_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        if ($adminRoleId) {
            $adminPermIds = DB::table('permissions')
                ->whereNotIn('name', ['tenant.create', 'tenant.delete', 'tenant.suspend'])
                ->pluck('permission_id');

            // 跳过已存在的映射
            $existingPermIds = DB::table('role_permissions')
                ->where('role_id', $adminRoleId)
                ->pluck('permission_id');

            $newPermIds = $adminPermIds->diff($existingPermIds);

            if ($newPermIds->isNotEmpty()) {
                $insertAdmin = $newPermIds->map(fn ($pid) => [
                    'role_id' => $adminRoleId,
                    'permission_id' => $pid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('role_permissions')->insert($insertAdmin);
            }
        }

        // platform_support: *.view 权限 + member.create, member.update, rbac.manage
        $supportRoleId = DB::table('roles')
            ->where('name', 'platform_support')
            ->whereNull('tenant_id')
            ->value('role_id');

        if ($supportRoleId) {
            $supportPermIds = DB::table('permissions')
                ->where(function ($query) {
                    $query->where('name', 'like', '%.view')
                        ->orWhereIn('name', ['member.create', 'member.update', 'rbac.manage']);
                })
                ->pluck('permission_id');

            $existingPermIds = DB::table('role_permissions')
                ->where('role_id', $supportRoleId)
                ->pluck('permission_id');

            $newPermIds = $supportPermIds->diff($existingPermIds);

            if ($newPermIds->isNotEmpty()) {
                $insertSupport = $newPermIds->map(fn ($pid) => [
                    'role_id' => $supportRoleId,
                    'permission_id' => $pid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('role_permissions')->insert($insertSupport);
            }
        }
    }

    public function down(): void
    {
        $roleNames = ['platform_admin', 'platform_support'];

        $roleIds = DB::table('roles')
            ->whereIn('name', $roleNames)
            ->whereNull('tenant_id')
            ->pluck('role_id');

        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('role_id', $roleIds)->delete();
    }
};
