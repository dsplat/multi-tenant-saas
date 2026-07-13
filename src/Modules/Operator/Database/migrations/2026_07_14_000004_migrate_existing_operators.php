<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\IdGeneratorContract;

return new class extends Migration
{
    public function up(): void
    {
        $idGenerator = app(IdGeneratorContract::class);
        $platformTenantId = config('id.platform_tenant_id', 9007199254740991);
        $now = now();

        // 1. Migrate users with role = 'super_admin' → operators + operator_tenants
        $superAdmins = DB::table('users')
            ->where('role', 'super_admin')
            ->get();

        foreach ($superAdmins as $user) {
            // Create operator record (idempotent by email)
            $operatorId = DB::table('operators')
                ->where('email', $user->email)
                ->value('operator_id');

            if (! $operatorId) {
                $operatorId = $idGenerator->generate();
                DB::table('operators')->insert([
                    'operator_id' => $operatorId,
                    'email' => $user->email,
                    'name' => $user->name,
                    'password' => $user->password,
                    'scope' => 'platform',
                    'is_active' => true,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Ensure user exists in platform tenant
            DB::table('tenant_users')->updateOrInsert(
                ['tenant_id' => $platformTenantId, 'user_id' => $user->user_id],
                [
                    'tenant_user_id' => $idGenerator->generate(),
                    'role' => 'super_admin',
                    'is_active' => true,
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // Create operator_tenants mapping (idempotent by operator_id + tenant_id)
            DB::table('operator_tenants')->updateOrInsert(
                ['operator_id' => $operatorId, 'tenant_id' => $platformTenantId],
                [
                    'user_id' => $user->user_id,
                    'role' => 'super_admin',
                    'is_active' => true,
                    'accepted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // 2. Migrate tenant_users with role = 'tenant_admin' → operators + operator_tenants
        $tenantAdmins = DB::table('tenant_users')
            ->where('role', 'tenant_admin')
            ->get();

        foreach ($tenantAdmins as $tenantUser) {
            $user = DB::table('users')->where('user_id', $tenantUser->user_id)->first();
            if (! $user) {
                continue;
            }

            // Find or create operator (idempotent by email)
            $operatorId = DB::table('operators')
                ->where('email', $user->email)
                ->value('operator_id');

            if (! $operatorId) {
                $operatorId = $idGenerator->generate();
                DB::table('operators')->insert([
                    'operator_id' => $operatorId,
                    'email' => $user->email,
                    'name' => $user->name,
                    'password' => $user->password,
                    'scope' => 'tenant',
                    'is_active' => true,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Create operator_tenants mapping (idempotent by operator_id + tenant_id)
            DB::table('operator_tenants')->updateOrInsert(
                ['operator_id' => $operatorId, 'tenant_id' => $tenantUser->tenant_id],
                [
                    'user_id' => $tenantUser->user_id,
                    'role' => 'tenant_admin',
                    'is_active' => true,
                    'accepted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Remove migrated operator_tenants entries for super_admin and tenant_admin
        DB::table('operator_tenants')
            ->whereIn('role', ['super_admin', 'tenant_admin'])
            ->delete();

        // Remove operators that were created by this migration
        // Only delete operators with scope platform or tenant that have no remaining operator_tenants
        $orphanOperatorIds = DB::table('operators')
            ->leftJoin('operator_tenants', 'operators.operator_id', '=', 'operator_tenants.operator_id')
            ->whereNull('operator_tenants.operator_id')
            ->whereIn('operators.scope', ['platform', 'tenant'])
            ->pluck('operators.operator_id');

        if ($orphanOperatorIds->isNotEmpty()) {
            DB::table('operators')->whereIn('operator_id', $orphanOperatorIds)->delete();
        }
    }
};
