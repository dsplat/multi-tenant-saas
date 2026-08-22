<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Commerce\Services\SupplySettlementService;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CommerceModule;

/**
 * Domain 保证金生命周期联动测试
 *
 * 覆盖：approve 自动锁定 + 幂等、deactivate 自动退还、金额为 0 关闭联动
 */
class DomainDepositTest extends TestCase
{
    protected array $uses = [CommerceModule::class, BillingModule::class];

    protected const TENANT_ID = 5301;

    protected const DEPOSIT_FEN = 10000;

    protected DomainService $domainService;

    protected SupplySettlementService $settlement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainService = new DomainService;
        $this->settlement = $this->app->make(SupplySettlementService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Domain Deposit Tenant',
            'slug' => 'domain-deposit',
            'domain' => 'deposit.example.com',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);

        config(['commerce.domain_deposit_fen' => self::DEPOSIT_FEN]);
    }

    private function depositBalance(): int
    {
        return (int) $this->settlement->depositAccount(self::TENANT_ID)->fresh()->balance;
    }

    public function test_approve_locks_deposit_idempotently(): void
    {
        $this->domainService->approveDomain(self::TENANT_ID, 901);

        $this->assertSame(self::DEPOSIT_FEN, $this->depositBalance());
        $this->assertNotNull(TenantSetting::get(
            self::TENANT_ID, DomainService::GROUP_DOMAIN, DomainService::SETTING_DEPOSIT_TX_ID
        ));
        $this->assertSame(
            self::DEPOSIT_FEN,
            (int) TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, DomainService::SETTING_DEPOSIT_AMOUNT)
        );
        $this->assertSame(DomainService::STATUS_APPROVED, $this->domainService->getDomainStatus(self::TENANT_ID));

        // 幂等：重复审批不重复锁定
        $this->domainService->approveDomain(self::TENANT_ID, 901);
        $this->assertSame(self::DEPOSIT_FEN, $this->depositBalance());
    }

    public function test_deactivate_releases_deposit(): void
    {
        $this->domainService->approveDomain(self::TENANT_ID, 901);
        $this->assertSame(self::DEPOSIT_FEN, $this->depositBalance());

        $this->domainService->deactivateDomain(self::TENANT_ID, 901);

        $this->assertSame(0, $this->depositBalance());
        $this->assertSame(DomainService::STATUS_REJECTED, $this->domainService->getDomainStatus(self::TENANT_ID));
        $this->assertNull(TenantSetting::get(
            self::TENANT_ID, DomainService::GROUP_DOMAIN, DomainService::SETTING_DEPOSIT_TX_ID
        ));

        // 幂等：再次停用不重复退还（无锁定记录）
        $this->domainService->deactivateDomain(self::TENANT_ID, 901);
        $this->assertSame(0, $this->depositBalance());
    }

    public function test_zero_config_disables_deposit_linkage(): void
    {
        config(['commerce.domain_deposit_fen' => 0]);

        $this->domainService->approveDomain(self::TENANT_ID, 901);

        $this->assertSame(DomainService::STATUS_APPROVED, $this->domainService->getDomainStatus(self::TENANT_ID));
        $this->assertSame(0, $this->depositBalance());
        $this->assertNull(TenantSetting::get(
            self::TENANT_ID, DomainService::GROUP_DOMAIN, DomainService::SETTING_DEPOSIT_TX_ID
        ));
    }
}
