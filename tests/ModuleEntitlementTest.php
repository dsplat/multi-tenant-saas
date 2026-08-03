<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Commerce\Models\ModuleEntitlement;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\CommerceModule;

/**
 * 模块权益判定测试（ModuleManager::isEnabledForTenant 权益层防御判定）
 *
 * 覆盖：无权益记录放行（系统授予）、仅过期权益拦截、有效权益放行、永久权益
 */
class ModuleEntitlementTest extends TestCase
{
    protected array $uses = [CommerceModule::class];

    protected ModuleManager $moduleManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleManager = $this->app->make(ModuleManager::class);

        Tenant::create([
            'tenant_id' => 2003,
            'name' => 'Entitlement Tenant',
            'slug' => 'entitlement-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('2003');

        // 预置租户开关为启用（coupon: tenant_toggleable + default_enabled）
        $this->moduleManager->enableForTenant('coupon', 2003);
    }

    private function createEntitlement(array $overrides = []): ModuleEntitlement
    {
        return ModuleEntitlement::withoutGlobalScope(TenantScope::class)->create(array_merge([
            'tenant_id' => 2003,
            'module_name' => 'coupon',
            'source' => ModuleEntitlement::SOURCE_PURCHASE,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(30),
            'status' => ModuleEntitlement::STATUS_ACTIVE,
        ], $overrides));
    }

    public function test_no_entitlement_record_is_treated_as_system_grant(): void
    {
        $this->assertTrue($this->moduleManager->isEnabledForTenant('coupon', 2003));
    }

    public function test_active_entitlement_passes(): void
    {
        $this->createEntitlement();

        $this->assertTrue($this->moduleManager->isEnabledForTenant('coupon', 2003));
    }

    public function test_lifetime_entitlement_passes(): void
    {
        $this->createEntitlement(['valid_until' => null]);

        $this->assertTrue($this->moduleManager->isEnabledForTenant('coupon', 2003));
    }

    public function test_only_expired_entitlement_blocks_access(): void
    {
        $this->createEntitlement(['valid_until' => now()->subDay()]);

        $this->assertFalse($this->moduleManager->isEnabledForTenant('coupon', 2003));
    }

    public function test_only_revoked_entitlement_blocks_access(): void
    {
        $this->createEntitlement(['status' => ModuleEntitlement::STATUS_REVOKED]);

        $this->assertFalse($this->moduleManager->isEnabledForTenant('coupon', 2003));
    }

    public function test_one_active_entitlement_among_expired_passes(): void
    {
        $this->createEntitlement(['valid_until' => now()->subDays(10)]);
        $this->createEntitlement(['valid_until' => now()->addDays(10)]);

        $this->assertTrue($this->moduleManager->isEnabledForTenant('coupon', 2003));
    }

    public function test_disabled_switch_blocks_even_with_active_entitlement(): void
    {
        $this->createEntitlement();
        $this->moduleManager->disableForTenant('coupon', 2003);

        // 开关关闭时，权益再有效也不放行（权益/开关分离：开关表租户当前意愿）
        $this->assertFalse($this->moduleManager->isEnabledForTenant('coupon', 2003));
    }

    public function test_entitlement_is_effective_helper(): void
    {
        $active = $this->createEntitlement();
        $this->assertTrue($active->isEffective());

        $expired = $this->createEntitlement(['valid_until' => now()->subDay()]);
        $this->assertFalse($expired->isEffective());

        $revoked = $this->createEntitlement(['status' => ModuleEntitlement::STATUS_REVOKED]);
        $this->assertFalse($revoked->isEffective());
    }
}
