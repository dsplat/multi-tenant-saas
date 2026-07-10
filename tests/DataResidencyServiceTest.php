<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Services\DataResidencyService;
use RuntimeException;

/**
 * TASK-029 DataResidencyService 单元测试
 *
 * 覆盖：区域配置查询、套餐区域限制、租户区域读写、合规强制开关、
 * 数据存储区域限制校验、合规校验、跨区域迁移（含禁用/同区/不一致/
 * 非法区域/套餐不允许等异常分支）。
 */
class DataResidencyServiceTest extends TestCase
{
    private DataResidencyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DataResidencyService;
    }

    /**
     * 创建测试租户
     */
    private function createTenant(int $tenantId, string $plan = 'pro', string $isolationType = 'shared'): Tenant
    {
        $tenant = Tenant::create([
            'tenant_id' => $tenantId,
            'name' => 'T' . $tenantId,
            'slug' => 'slug-' . $tenantId,
            'status' => 'active',
            'subscription_plan' => $plan,
        ]);
        $tenant->isolation_type = $isolationType;
        $tenant->save();

        return $tenant;
    }

    // ---------- 区域配置 ----------

    public function test_get_available_regions_returns_configured_regions(): void
    {
        $regions = $this->service->getAvailableRegions();

        $this->assertIsArray($regions);
        $this->assertArrayHasKey('CN', $regions);
        $this->assertArrayHasKey('US', $regions);
        $this->assertArrayHasKey('EU', $regions);
        $this->assertArrayHasKey('APAC', $regions);
    }

    public function test_get_default_region(): void
    {
        $this->assertSame('CN', $this->service->getDefaultRegion());
    }

    public function test_is_valid_region(): void
    {
        $this->assertTrue($this->service->isValidRegion('CN'));
        $this->assertTrue($this->service->isValidRegion('US'));
        $this->assertFalse($this->service->isValidRegion('UNKNOWN'));
    }

    public function test_get_storage_disk_for_valid_region(): void
    {
        $disk = $this->service->getStorageDisk('CN');
        $this->assertIsString($disk);
        $this->assertNotEmpty($disk);
    }

    public function test_get_storage_disk_throws_for_invalid_region(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->getStorageDisk('UNKNOWN');
    }

    // ---------- 套餐区域限制 ----------

    public function test_get_plan_allowed_regions_for_free(): void
    {
        $allowed = $this->service->getPlanAllowedRegions('free');
        $this->assertSame(['CN'], $allowed);
    }

    public function test_get_plan_allowed_regions_for_pro(): void
    {
        $allowed = $this->service->getPlanAllowedRegions('pro');
        $this->assertContains('CN', $allowed);
        $this->assertContains('US', $allowed);
        $this->assertContains('EU', $allowed);
        $this->assertContains('APAC', $allowed);
    }

    public function test_get_plan_allowed_regions_for_unknown_plan_returns_all(): void
    {
        $allowed = $this->service->getPlanAllowedRegions('unknown_plan');
        $this->assertCount(4, $allowed);
    }

    public function test_is_region_allowed_for_plan(): void
    {
        $this->assertTrue($this->service->isRegionAllowedForPlan('free', 'CN'));
        $this->assertFalse($this->service->isRegionAllowedForPlan('free', 'US'));
        $this->assertTrue($this->service->isRegionAllowedForPlan('pro', 'US'));
    }

    // ---------- 租户区域读写 ----------

    public function test_get_tenant_region_returns_default_when_not_set(): void
    {
        $this->createTenant(2901);
        $this->assertSame('CN', $this->service->getTenantRegion(2901));
    }

    public function test_set_and_get_tenant_region(): void
    {
        $this->createTenant(2902, 'pro');
        $this->service->setTenantRegion(2902, 'US');

        $this->assertSame('US', $this->service->getTenantRegion(2902));
    }

    public function test_set_tenant_region_throws_for_invalid_region(): void
    {
        $this->createTenant(2903);

        $this->expectException(RuntimeException::class);
        $this->service->setTenantRegion(2903, 'UNKNOWN');
    }

    public function test_set_tenant_region_throws_when_plan_not_allowed(): void
    {
        $this->createTenant(2904, 'free');

        $this->expectException(RuntimeException::class);
        $this->service->setTenantRegion(2904, 'US');
    }

    public function test_set_tenant_region_throws_when_tenant_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->setTenantRegion(999999, 'US');
    }

    // ---------- 合规强制开关 ----------

    public function test_is_compliance_enforced_default(): void
    {
        $this->createTenant(2905);
        // 默认配置 compliance_enforced = true
        $this->assertTrue($this->service->isComplianceEnforced(2905));
    }

    public function test_set_compliance_enforced(): void
    {
        $this->createTenant(2906);
        $this->service->setComplianceEnforced(2906, false);

        $this->assertFalse($this->service->isComplianceEnforced(2906));
    }

    // ---------- 数据存储区域限制 ----------

    public function test_enforce_storage_region_passes_when_match(): void
    {
        $this->createTenant(2907, 'pro');
        $this->service->setTenantRegion(2907, 'US');

        $this->assertTrue($this->service->enforceStorageRegion(2907, 'US'));
    }

    public function test_enforce_storage_region_throws_when_violation_and_enforced(): void
    {
        $this->createTenant(2908, 'pro');
        $this->service->setTenantRegion(2908, 'CN');
        $this->service->setComplianceEnforced(2908, true);

        $this->expectException(RuntimeException::class);
        $this->service->enforceStorageRegion(2908, 'US');
    }

    public function test_enforce_storage_region_returns_false_when_not_enforced(): void
    {
        $this->createTenant(2909, 'pro');
        $this->service->setTenantRegion(2909, 'CN');
        $this->service->setComplianceEnforced(2909, false);

        $this->assertFalse($this->service->enforceStorageRegion(2909, 'US', true));
    }

    public function test_enforce_storage_region_returns_false_without_throwing(): void
    {
        $this->createTenant(2910, 'pro');
        $this->service->setTenantRegion(2910, 'CN');
        $this->service->setComplianceEnforced(2910, true);

        $this->assertFalse($this->service->enforceStorageRegion(2910, 'US', false));
    }

    // ---------- 合规校验 ----------

    public function test_validate_compliance_passes_when_match(): void
    {
        $this->createTenant(2911, 'pro');
        $this->service->setTenantRegion(2911, 'EU');

        $this->assertTrue($this->service->validateCompliance(2911, 'EU'));
    }

    public function test_validate_compliance_fails_when_mismatch(): void
    {
        $this->createTenant(2912, 'pro');
        $this->service->setTenantRegion(2912, 'CN');

        $this->assertFalse($this->service->validateCompliance(2912, 'US'));
    }

    // ---------- 跨区域迁移 ----------

    public function test_migrate_region_success_updates_region_metadata(): void
    {
        // 使用 database 隔离策略，跳过 IsolationService 底层数据搬迁
        $this->createTenant(2913, 'pro', 'database');
        $this->service->setTenantRegion(2913, 'CN');

        $this->service->migrateRegion(2913, 'CN', 'US');

        $this->assertSame('US', $this->service->getTenantRegion(2913));
    }

    public function test_migrate_region_throws_when_disabled(): void
    {
        $this->createTenant(2914, 'pro');
        $this->service->setTenantRegion(2914, 'CN');

        config(['tenancy.residency.cross_region_migration_enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->service->migrateRegion(2914, 'CN', 'US');
    }

    public function test_migrate_region_throws_when_same_region(): void
    {
        $this->createTenant(2915, 'pro');
        $this->service->setTenantRegion(2915, 'CN');

        $this->expectException(RuntimeException::class);
        $this->service->migrateRegion(2915, 'CN', 'CN');
    }

    public function test_migrate_region_throws_on_invalid_region(): void
    {
        $this->createTenant(2916, 'pro');
        $this->service->setTenantRegion(2916, 'CN');

        $this->expectException(RuntimeException::class);
        $this->service->migrateRegion(2916, 'CN', 'UNKNOWN');
    }

    public function test_migrate_region_throws_on_mismatch(): void
    {
        $this->createTenant(2917, 'pro');
        $this->service->setTenantRegion(2917, 'CN');

        // 声明 from=US，但实际区域为 CN
        $this->expectException(RuntimeException::class);
        $this->service->migrateRegion(2917, 'US', 'EU');
    }

    public function test_migrate_region_throws_when_plan_not_allowed(): void
    {
        $this->createTenant(2918, 'free');
        $this->service->setTenantRegion(2918, 'CN');

        // free 套餐仅允许 CN，迁移到 US 应被拒绝
        $this->expectException(RuntimeException::class);
        $this->service->migrateRegion(2918, 'CN', 'US');
    }

    public function test_migrate_region_throws_when_tenant_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->migrateRegion(999999, 'CN', 'US');
    }

    // ---------- TenantSetting 集成 ----------

    public function test_set_tenant_region_persists_in_tenant_setting(): void
    {
        $this->createTenant(2919, 'pro');
        $this->service->setTenantRegion(2919, 'APAC');

        $persisted = TenantSetting::get(2919, 'residency', 'region');
        $this->assertSame('APAC', $persisted);
    }
}
