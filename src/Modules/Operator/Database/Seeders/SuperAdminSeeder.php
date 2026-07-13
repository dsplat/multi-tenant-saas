<?php

namespace MultiTenantSaas\Modules\Operator\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Auth\Models\User;

/**
 * 超级管理员 Seeder
 *
 * 创建超级管理员 operator（独立账号体系）和对应的 user（平台租户内）。
 * 如果 operators/operator_tenants 表不存在，仅创建 user 和 tenant_users 映射。
 */
class SuperAdminSeeder extends Seeder
{
    const DEFAULT_EMAIL = 'admin@platform.local';

    const DEFAULT_PASSWORD = 'admin123456';

    public function run(): void
    {
        $idGenerator = app(IdGeneratorContract::class);
        $platformTenantId = config('id.platform_tenant_id', 9007199254740991);

        // 确保平台租户存在
        if (! DB::table('tenants')->where('tenant_id', $platformTenantId)->exists()) {
            $this->command->warn('平台默认租户不存在，请先运行 PlatformTenantSeeder');

            return;
        }

        $email = env('SUPER_ADMIN_EMAIL', self::DEFAULT_EMAIL);
        $password = env('SUPER_ADMIN_PASSWORD', self::DEFAULT_PASSWORD);
        $hashedPassword = Hash::make($password);
        $now = now();

        // 1. 创建 operator（独立账号体系，如果表存在）
        $operatorId = null;
        if (Schema::hasTable('operators')) {
            $operatorId = DB::table('operators')->where('email', $email)->value('operator_id');

            if (! $operatorId) {
                $operatorId = $idGenerator->generate();
                DB::table('operators')->insert([
                    'operator_id' => $operatorId,
                    'email' => $email,
                    'name' => '超级管理员',
                    'password' => $hashedPassword,
                    'scope' => 'platform',
                    'is_active' => true,
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->command->info("Operator 已创建: {$email}");
            } else {
                $this->command->info("Operator 已存在: {$email}");
            }
        }

        // 2. 创建 user（平台租户内的用户）
        $user = User::where('tenant_id', $platformTenantId)->where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'user_id' => $idGenerator->generate(),
                'tenant_id' => $platformTenantId,
                'name' => '超级管理员',
                'email' => $email,
                'password' => $hashedPassword,
                'is_active' => true,
                'email_verified_at' => $now,
            ]);
            $this->command->info("User 已创建: {$email} (tenant_id={$platformTenantId})");
        } else {
            $this->command->info("User 已存在: {$email}");
        }

        // 3. 关联 tenant_users（遗留兼容，确保 tenant_users 也有记录）
        $superAdminRoleId = DB::table('roles')
            ->where('name', 'super_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        DB::table('tenant_users')->updateOrInsert(
            ['tenant_id' => $platformTenantId, 'user_id' => $user->user_id],
            [
                'tenant_user_id' => $idGenerator->generate(),
                'role' => 'super_admin',
                'role_id' => $superAdminRoleId,
                'is_active' => true,
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 4. 创建 operator_tenants 映射（如果表存在）
        if ($operatorId && Schema::hasTable('operator_tenants')) {
            DB::table('operator_tenants')->updateOrInsert(
                ['operator_id' => $operatorId, 'tenant_id' => $platformTenantId],
                [
                    'user_id' => $user->user_id,
                    'role' => 'super_admin',
                    'role_id' => $superAdminRoleId,
                    'is_active' => true,
                    'accepted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $this->command->info('Operator-Tenant 映射已创建');
        }

        $this->command->info("超级管理员初始化完成: {$email}");
    }
}
