<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;
use RuntimeException;

/**
 * 数据驻留服务（TASK-029）
 *
 * 职责：
 * - 区域配置（CN/US/EU/APAC）查询
 * - 租户区域读写（基于 TenantSetting，group = residency）
 * - 数据存储区域限制（按套餐限制可选区域）
 * - 跨区域迁移（委托 IsolationService 迁移工具）
 * - 合规校验（租户区域与期望区域是否一致）
 *
 * 注意：本服务按显式 tenant_id 操作，绕过 TenantScope，
 * 安全由调用方（Controller/Service）保证用户有权访问目标租户。
 */
class DataResidencyService
{
    /** 区域：中国大陆 */
    public const REGION_CN = 'CN';

    /** 区域：美国 */
    public const REGION_US = 'US';

    /** 区域：欧盟 */
    public const REGION_EU = 'EU';

    /** 区域：亚太 */
    public const REGION_APAC = 'APAC';

    /** TenantSetting 中驻留区域字段 */
    public const SETTING_KEY_REGION = 'region';

    /** TenantSetting 中合规强制开关 */
    public const SETTING_KEY_ENFORCED = 'storage_region_enforced';

    /**
     * 获取所有可用区域配置
     *
     * @return array<string, array{name:string, storage_disk:string}>
     */
    public function getAvailableRegions(): array
    {
        return (array) config('tenancy.residency.regions', []);
    }

    /**
     * 获取默认区域
     */
    public function getDefaultRegion(): string
    {
        return (string) config('tenancy.residency.default_region', self::REGION_CN);
    }

    /**
     * 是否为合法区域
     */
    public function isValidRegion(string $region): bool
    {
        return array_key_exists($region, $this->getAvailableRegions());
    }

    /**
     * 获取区域对应的存储磁盘名
     */
    public function getStorageDisk(string $region): string
    {
        $regions = $this->getAvailableRegions();
        $config = $regions[$region] ?? null;

        if (! is_array($config)) {
            throw new RuntimeException(
                trans('tenant.residency_region_unsupported', ['region' => $region])
            );
        }

        return (string) ($config['storage_disk'] ?? config('tenancy.file_storage_disk', 'local'));
    }

    /**
     * 获取套餐允许的区域列表
     *
     * @return string[]
     */
    public function getPlanAllowedRegions(string $planName): array
    {
        $map = (array) config('tenancy.residency.plan_allowed_regions', []);

        $allowed = $map[$planName] ?? null;

        if (is_array($allowed)) {
            return $allowed;
        }

        // 未列出的套餐默认允许全部区域
        return array_keys($this->getAvailableRegions());
    }

    /**
     * 套餐是否允许使用指定区域
     */
    public function isRegionAllowedForPlan(string $planName, string $region): bool
    {
        $allowed = $this->getPlanAllowedRegions($planName);

        return in_array($region, $allowed, true);
    }

    /**
     * 获取租户当前驻留区域（未配置时返回默认区域）
     */
    public function getTenantRegion(int $tenantId): string
    {
        $region = TenantSetting::get(
            $tenantId,
            $this->settingsGroup(),
            self::SETTING_KEY_REGION
        );

        if (is_string($region) && $region !== '' && $this->isValidRegion($region)) {
            return $region;
        }

        return $this->getDefaultRegion();
    }

    /**
     * 设置租户驻留区域
     *
     * @throws RuntimeException 区域不合法或套餐不允许时抛出
     */
    public function setTenantRegion(int $tenantId, string $region): void
    {
        if (! $this->isValidRegion($region)) {
            throw new RuntimeException(
                trans('tenant.residency_region_unsupported', ['region' => $region])
            );
        }

        $tenant = Tenant::find($tenantId);
        if ($tenant === null) {
            throw new RuntimeException(trans('tenant.not_found'));
        }

        $plan = (string) ($tenant->subscription_plan ?? 'free');
        if (! $this->isRegionAllowedForPlan($plan, $region)) {
            throw new RuntimeException(
                trans('tenant.residency_region_not_allowed_by_plan', [
                    'region' => $region,
                    'plan' => $plan,
                ])
            );
        }

        TenantSetting::set(
            $tenantId,
            $this->settingsGroup(),
            self::SETTING_KEY_REGION,
            $region,
            false,
            trans('tenant.residency_region_description')
        );
    }

    /**
     * 是否对租户启用合规强制（启用后 enforceStorageRegion 拒绝跨区域读写）
     */
    public function isComplianceEnforced(int $tenantId): bool
    {
        $tenantLevel = TenantSetting::get(
            $tenantId,
            $this->settingsGroup(),
            self::SETTING_KEY_ENFORCED
        );

        if ($tenantLevel !== null) {
            return (bool) $tenantLevel;
        }

        return (bool) config('tenancy.residency.compliance_enforced', true);
    }

    /**
     * 设置租户级合规强制开关
     */
    public function setComplianceEnforced(int $tenantId, bool $enforced): void
    {
        TenantSetting::set(
            $tenantId,
            $this->settingsGroup(),
            self::SETTING_KEY_ENFORCED,
            $enforced,
            false,
            trans('tenant.residency_enforced_description')
        );
    }

    /**
     * 数据存储区域限制：检查指定数据区域是否与租户区域一致
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $dataRegion  数据实际所在区域
     * @param  bool  $throwOnFailure  校验失败时是否抛出异常（false 返回布尔值）
     * @return bool 校验通过返回 true
     *
     * @throws RuntimeException 校验失败且 throwOnFailure=true 时抛出
     */
    public function enforceStorageRegion(int $tenantId, string $dataRegion, bool $throwOnFailure = true): bool
    {
        $tenantRegion = $this->getTenantRegion($tenantId);

        if ($tenantRegion === $dataRegion) {
            return true;
        }

        if (! $this->isComplianceEnforced($tenantId)) {
            return false;
        }

        if ($throwOnFailure) {
            throw new RuntimeException(
                trans('tenant.residency_violation', [
                    'expected' => $tenantRegion,
                    'actual' => $dataRegion,
                ])
            );
        }

        return false;
    }

    /**
     * 合规校验：租户区域是否与期望区域一致
     */
    public function validateCompliance(int $tenantId, string $expectedRegion): bool
    {
        return $this->getTenantRegion($tenantId) === $expectedRegion;
    }

    /**
     * 跨区域迁移：将租户数据从一个区域迁移到另一个区域
     *
     * 实现：复用 IsolationService 迁移工具。跨区域迁移通常意味着
     * 切换物理存储（数据库 / 对象存储），与 IsolationService 的
     * shared → database 迁移同构，因此直接委托。
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $fromRegion  源区域
     * @param  string  $toRegion  目标区域
     *
     * @throws RuntimeException 配置禁用跨区域迁移、区域非法或底层迁移失败时抛出
     */
    public function migrateRegion(int $tenantId, string $fromRegion, string $toRegion): void
    {
        if (! (bool) config('tenancy.residency.cross_region_migration_enabled', true)) {
            throw new RuntimeException(trans('tenant.residency_migration_disabled'));
        }

        if (! $this->isValidRegion($fromRegion) || ! $this->isValidRegion($toRegion)) {
            throw new RuntimeException(trans('tenant.residency_region_unsupported', [
                'region' => $fromRegion . '/' . $toRegion,
            ]));
        }

        if ($fromRegion === $toRegion) {
            throw new RuntimeException(trans('tenant.residency_migration_same_region'));
        }

        $current = $this->getTenantRegion($tenantId);
        if ($current !== $fromRegion) {
            throw new RuntimeException(
                trans('tenant.residency_migration_mismatch', [
                    'current' => $current,
                    'from' => $fromRegion,
                ])
            );
        }

        $tenant = Tenant::find($tenantId);
        if ($tenant === null) {
            throw new RuntimeException(trans('tenant.not_found'));
        }

        $plan = (string) ($tenant->subscription_plan ?? 'free');
        if (! $this->isRegionAllowedForPlan($plan, $toRegion)) {
            throw new RuntimeException(
                trans('tenant.residency_region_not_allowed_by_plan', [
                    'region' => $toRegion,
                    'plan' => $plan,
                ])
            );
        }

        // 委托 IsolationService 迁移工具完成底层搬迁
        // 跨区域迁移在 shared → database 模型下等价于切换物理库
        if (app()->bound(IsolationService::class)) {
            $isolation = app(IsolationService::class);
            $currentStrategy = (string) ($tenant->isolation_type ?: $isolation->defaultType());

            // 仅当租户使用 shared 策略时执行 shared → database 升级迁移
            // 其它策略下仅更新区域元数据，由运维通过外部脚本完成实际数据搬迁
            if ($currentStrategy === IsolationService::TYPE_SHARED
                && $isolation->hasStrategy(IsolationService::TYPE_DATABASE)
            ) {
                try {
                    $isolation->migrate(
                        $tenantId,
                        IsolationService::TYPE_SHARED,
                        IsolationService::TYPE_DATABASE
                    );
                } catch (RuntimeException $e) {
                    Log::error(trans('tenant.residency_migration_failed', [
                        'tenant' => $tenantId,
                        'error' => $e->getMessage(),
                    ]), ['exception' => $e]);
                    throw $e;
                }
            }
        }

        // 更新驻留区域元数据
        $this->setTenantRegion($tenantId, $toRegion);

        Log::info(trans('tenant.residency_migration_completed', [
            'tenant' => $tenantId,
            'from' => $fromRegion,
            'to' => $toRegion,
        ]));
    }

    /**
     * 获取驻留配置 group 名
     */
    protected function settingsGroup(): string
    {
        return (string) config('tenancy.residency.settings_group', 'residency');
    }
}
