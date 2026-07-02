<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC 权限模块
 * 表: permissions, roles, role_permissions
 */
class RbacModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id')->primary();
            $table->string('name', 100)->unique();
            $table->string('display_name', 200);
            $table->string('group', 50)->default('general');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->string('name', 50);
            $table->string('display_name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['permissions', 'roles', 'role_permissions'];
    }
}
