<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Billing\Models\CreditTransaction;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Commerce\Services\SupplySettlementService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CommerceModule;

/**
 * 供货结算服务测试（Phase 4）
 *
 * 覆盖：预存账户/充值、锁库存/释放、结算扣款（幂等+不足拒绝）、
 * 补偿回补、保证金锁定/退还/扣除
 */
class SupplySettlementServiceTest extends TestCase
{
    protected array $uses = [CommerceModule::class, BillingModule::class];

    protected SupplySettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(SupplySettlementService::class);

        Tenant::create([
            'tenant_id' => 4001,
            'name' => 'Supply Settlement Tenant',
            'slug' => 'supply-settlement',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('4001');
    }

    private function createGrant(int $allocated = 10, float $supplyPriceYuan = 5.0): SupplyGrant
    {
        $sku = CommerceSku::create([
            'name' => '供货 SKU',
            'type' => CommerceSku::TYPE_MALL_SUPPLY,
            'role' => CommerceSku::ROLE_SUPPLY,
            'lifecycle' => 'grant',
            'fulfill_handler' => CommerceSku::TYPE_MALL_SUPPLY,
            'price' => 99.00,
            'status' => CommerceSku::STATUS_ACTIVE,
        ]);

        return SupplyGrant::create([
            'tenant_id' => 4001,
            'sku_id' => $sku->sku_id,
            'status' => SupplyGrant::STATUS_ACTIVE,
            'valid_from' => now(),
            'settlement' => ['supply_price' => $supplyPriceYuan],
            'allocated_qty' => $allocated,
            'remaining_qty' => $allocated,
            'locked_qty' => 0,
        ]);
    }

    // ========== 预存账户与充值 ==========

    public function test_prepay_account_get_or_create_and_recharge(): void
    {
        $account = $this->service->prepayAccount(4001);
        $this->assertEquals('supply_prepay', $account->account_type);
        $this->assertEquals(0, $account->balance);

        // 幂等：同一租户同一类型不重复建户
        $this->assertEquals($account->getKey(), $this->service->prepayAccount(4001)->getKey());

        $tx = $this->service->rechargePrepay(4001, 100000, 901, '首期充值');
        $this->assertEquals(100000, $tx->amount);
        $this->assertEquals(100000, $account->fresh()->balance);

        // 与 AI credit 账户分账
        $this->assertNotEquals('supply_prepay', $this->service->depositAccount(4001)->account_type);
    }

    // ========== 锁库存 ==========

    public function test_lock_stock_moves_remaining_to_locked(): void
    {
        $grant = $this->createGrant(10);

        $this->service->lockStock($grant, 3, 'mall_order', 'MO-1');

        $fresh = $grant->fresh();
        $this->assertEquals(7, $fresh->remaining_qty);
        $this->assertEquals(3, $fresh->locked_qty);
    }

    public function test_lock_stock_rejects_over_remaining(): void
    {
        $grant = $this->createGrant(2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('可下发余量不足');
        $this->service->lockStock($grant, 3, 'mall_order', 'MO-2');
    }

    public function test_lock_stock_rejects_non_stock_grant(): void
    {
        $grant = $this->createGrant(0); // 非库存型

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('非库存型');
        $this->service->lockStock($grant, 1, 'mall_order', 'MO-3');
    }

    public function test_lock_stock_rejects_suspended_grant(): void
    {
        $grant = $this->createGrant(10);
        $grant->update(['status' => SupplyGrant::STATUS_SUSPENDED]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未生效');
        $this->service->lockStock($grant->fresh(), 1, 'mall_order', 'MO-4');
    }

    public function test_unlock_stock_restores_remaining(): void
    {
        $grant = $this->createGrant(10);
        $this->service->lockStock($grant, 3, 'mall_order', 'MO-5');

        $this->service->unlockStock($grant, 3);

        $fresh = $grant->fresh();
        $this->assertEquals(10, $fresh->remaining_qty);
        $this->assertEquals(0, $fresh->locked_qty);

        // 超额释放只释放到 0
        $this->service->lockStock($grant->fresh(), 1, 'mall_order', 'MO-6');
        $this->service->unlockStock($grant->fresh(), 99);
        $this->assertEquals(0, $grant->fresh()->locked_qty);
    }

    // ========== 结算 ==========

    public function test_settle_deducts_prepay_and_consumes_locked(): void
    {
        $grant = $this->createGrant(10, 5.0);
        $this->service->rechargePrepay(4001, 100000, 901);
        $this->service->lockStock($grant, 2, 'mall_order', 'MO-10');

        $tx = $this->service->settle($grant, 2, 'mall_order', 'MO-10');

        $this->assertEquals(-1000, $tx->amount); // 500 分 x 2
        $this->assertEquals('consume', $tx->type);
        $this->assertEquals('mall_order', $tx->related_type);
        $this->assertEquals('MO-10', $tx->related_id);

        $fresh = $grant->fresh();
        $this->assertEquals(0, $fresh->locked_qty);
        $this->assertEquals(8, $fresh->remaining_qty);

        $account = $this->service->prepayAccount(4001);
        $this->assertEquals(99000, $account->fresh()->balance);
        $this->assertEquals(1000, $account->fresh()->total_consumed);
    }

    public function test_settle_rejects_insufficient_prepay_and_rolls_back(): void
    {
        $grant = $this->createGrant(10, 5.0);
        $this->service->rechargePrepay(4001, 600, 901); // 只够 1 件
        $this->service->lockStock($grant, 2, 'mall_order', 'MO-11');

        try {
            $this->service->settle($grant, 2, 'mall_order', 'MO-11');
            $this->fail('预存不足应拒绝结算');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('预存货款不足', $e->getMessage());
        }

        // 回滚：余额与锁定量不变
        $this->assertEquals(600, $this->service->prepayAccount(4001)->fresh()->balance);
        $this->assertEquals(2, $grant->fresh()->locked_qty);
    }

    public function test_settle_is_idempotent(): void
    {
        $grant = $this->createGrant(10, 5.0);
        $this->service->rechargePrepay(4001, 100000, 901);
        $this->service->lockStock($grant, 2, 'mall_order', 'MO-12');

        $first = $this->service->settle($grant, 2, 'mall_order', 'MO-12');
        $second = $this->service->settle($grant, 2, 'mall_order', 'MO-12');

        $this->assertEquals($first->getKey(), $second->getKey());
        $this->assertEquals(99000, $this->service->prepayAccount(4001)->fresh()->balance, '重复结算不应二次扣款');
    }

    public function test_settle_free_grant_records_zero_amount(): void
    {
        $grant = $this->createGrant(5, 0); // 免费划拨
        $this->service->lockStock($grant, 1, 'mall_order', 'MO-13');

        $tx = $this->service->settle($grant, 1, 'mall_order', 'MO-13');

        $this->assertEquals(0, $tx->amount);
        $this->assertEquals(0, $this->service->prepayAccount(4001)->fresh()->balance);
    }

    // ========== 补偿 ==========

    public function test_compensate_refunds_and_restores_stock(): void
    {
        $grant = $this->createGrant(10, 5.0);
        $this->service->rechargePrepay(4001, 100000, 901);
        $this->service->lockStock($grant, 2, 'mall_order', 'MO-20');
        $this->service->settle($grant, 2, 'mall_order', 'MO-20');

        $refundTx = $this->service->compensate($grant, 2, 'mall_order', 'MO-20');

        $this->assertNotNull($refundTx);
        $this->assertEquals(1000, $refundTx->amount);
        $this->assertEquals('refund', $refundTx->type);

        $fresh = $grant->fresh();
        $this->assertEquals(10, $fresh->remaining_qty); // 库存回补
        $this->assertEquals(100000, $this->service->prepayAccount(4001)->fresh()->balance);
    }

    public function test_compensate_is_idempotent_and_returns_null_when_not_settled(): void
    {
        $grant = $this->createGrant(10, 5.0);
        $this->service->rechargePrepay(4001, 100000, 901);

        // 未结算过
        $this->assertNull($this->service->compensate($grant, 1, 'mall_order', 'MO-21'));

        $this->service->lockStock($grant, 1, 'mall_order', 'MO-21');
        $this->service->settle($grant, 1, 'mall_order', 'MO-21');

        $first = $this->service->compensate($grant, 1, 'mall_order', 'MO-21');
        $second = $this->service->compensate($grant, 1, 'mall_order', 'MO-21');
        $this->assertEquals($first->getKey(), $second->getKey());
        $this->assertEquals(100000, $this->service->prepayAccount(4001)->fresh()->balance, '重复补偿不应二次退款');
    }

    // ========== 域名保证金 ==========

    public function test_deposit_lock_release_and_deduct(): void
    {
        $this->service->lockDeposit(4001, 50000, 901, '二级域名开通');
        $account = $this->service->depositAccount(4001);
        $this->assertEquals('domain_deposit', $account->account_type);
        $this->assertEquals(50000, $account->fresh()->balance);

        // 违规扣除一部分
        $this->service->deductDeposit(4001, 10000, 901, '违规扣除');
        $this->assertEquals(40000, $account->fresh()->balance);

        // 退出退还剩余
        $releaseTx = $this->service->releaseDeposit(4001, 40000, 901, '域名退出退还');
        $this->assertEquals(-40000, $releaseTx->amount);
        $this->assertEquals('release', $releaseTx->type);
        $this->assertEquals(0, $account->fresh()->balance);
    }

    public function test_deposit_rejects_over_balance(): void
    {
        $this->service->lockDeposit(4001, 1000, 901);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('保证金余额不足');
        $this->service->releaseDeposit(4001, 2000, 901);
    }

    public function test_prepay_and_deposit_ledgers_are_isolated(): void
    {
        $this->service->rechargePrepay(4001, 10000, 901);
        $this->service->lockDeposit(4001, 5000, 901);

        $this->assertEquals(10000, $this->service->prepayAccount(4001)->fresh()->balance);
        $this->assertEquals(5000, $this->service->depositAccount(4001)->fresh()->balance);

        $prepayTxCount = CreditTransaction::withoutGlobalScope(TenantScope::class)
            ->where('account_id', $this->service->prepayAccount(4001)->getKey())
            ->count();
        $this->assertEquals(1, $prepayTxCount, '保证金流水不得混入预存账户');
    }
}
