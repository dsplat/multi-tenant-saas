<?php

namespace MultiTenantSaas\Modules\Operator\Database\Seeders;

use Illuminate\Database\Seeder;
use MultiTenantSaas\Modules\Domain\Services\SlugService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 平台超级租户 Seeder
 *
 * 创建平台默认超级租户，ID 固定为 Number.MAX_SAFE_INTEGER (9007199254740991)。
 * 这个值确保在 JavaScript 和 PHP 中都是安全的整数，同时天然充当 ID 空间上界哨兵。
 *
 * 定位：它是一个【完整可用的租户】而非占位壳——
 * - 用于部署后的部署校验（登录/功能验收）
 * - 用于承载平台自身服务的数据
 * 下游项目应在其上叠加租户级开通（模块开通/默认配置/业务种子）。
 *
 * slug 策略：初始化时自动生成 t-xxxxxx 自动码（不再硬编码 platform——
 * 该词已列入 reserved_slugs，防止租户子域名与平台域混淆）；
 * 重跑 seed 不触碰存量 slug，避免访问入口变化。
 */
class PlatformTenantSeeder extends Seeder
{
    /**
     * 平台租户 ID（Number.MAX_SAFE_INTEGER）
     */
    const PLATFORM_TENANT_ID = 9007199254740991;

    public function run(): void
    {
        $values = [
            'name' => '平台超级租户',
            'status' => 'active',
            'subscription_plan' => 'enterprise',
            'subscription_started_at' => now(),
            'total_credits' => 999999,
            'used_credits' => 0,
            'is_platform_default' => true,
            'onboarding_completed' => true,
            'settings' => json_encode([]),
            'branding' => json_encode([]),
            'description' => '平台默认超级租户：部署校验 + 平台服务承载（ID 空间上界哨兵，禁止删除）',
        ];

        $existing = Tenant::find(self::PLATFORM_TENANT_ID);

        if ($existing) {
            // 重跑 seed：不触碰 slug（避免访问入口变化）
            $existing->update($values);
        } else {
            Tenant::create(array_merge([
                'tenant_id' => self::PLATFORM_TENANT_ID,
                'slug' => (new SlugService)->generateUniqueAutoSlug(),
                'slug_status' => 'active',
            ], $values));
        }

        $this->command->info('平台超级租户已就绪: ' . self::PLATFORM_TENANT_ID);
    }
}
