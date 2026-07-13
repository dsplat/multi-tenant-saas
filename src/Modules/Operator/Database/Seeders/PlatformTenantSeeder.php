<?php

namespace MultiTenantSaas\Modules\Operator\Database\Seeders;

use Illuminate\Database\Seeder;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 平台默认租户 Seeder
 *
 * 创建平台默认租户，ID 固定为 Number.MAX_SAFE_INTEGER (9007199254740991)
 * 这个值确保在 JavaScript 和 PHP 中都是安全的整数
 */
class PlatformTenantSeeder extends Seeder
{
    /**
     * 平台租户 ID（Number.MAX_SAFE_INTEGER）
     */
    const PLATFORM_TENANT_ID = 9007199254740991;

    public function run(): void
    {
        Tenant::updateOrCreate(
            ['tenant_id' => self::PLATFORM_TENANT_ID],
            [
                'name' => '平台默认租户',
                'slug' => 'platform',
                'status' => 'active',
                'subscription_plan' => 'free',
                'is_platform_default' => true,
                'description' => '公共平台用户默认租户',
            ]
        );

        $this->command->info('平台默认租户已创建: ' . self::PLATFORM_TENANT_ID);
    }
}
