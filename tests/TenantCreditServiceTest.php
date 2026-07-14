<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Billing\Models\CreditTransaction;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantCreditService;
use MultiTenantSaas\Tests\Schema\BillingModule;

/**
 * TenantCreditService 单元测试
 *
 * 覆盖：积分账户创建、积分充值、积分消费、积分退款
 */
class TenantCreditServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Credit Tenant', 'slug' => 'credit-tenant', 'status' => 'active']);

        User::unguarded(function () {
            User::create([
                'user_id' => 2001,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'password' => bcrypt('secret'),
            ]);
        });

        TenantContext::setTenantId('1001');
    }

    // ---------- 积分账户创建 ----------

    public function test_get_account_info_creates_enterprise_account_if_not_exists(): void
    {
        $service = app(TenantCreditService::class);

        $info = $service->getAccountInfo(1001);

        $this->assertNotNull($info['account']);
        $this->assertEquals(0, $info['balance']);
        $this->assertEquals(0, $info['total_recharged']);
        $this->assertEquals(0, $info['total_consumed']);

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();
        $this->assertNotNull($account);
    }

    public function test_get_account_info_returns_existing_account(): void
    {
        CreditAccount::unguarded(function () {
            CreditAccount::create([
                'credit_account_id' => 9001,
                'tenant_id' => 1001,
                'account_type' => 'enterprise',
                'balance' => 500,
                'total_recharged' => 1000,
                'total_consumed' => 500,
            ]);
        });

        $service = app(TenantCreditService::class);

        $info = $service->getAccountInfo(1001);

        $this->assertEquals(500, $info['balance']);
        $this->assertEquals(1000, $info['total_recharged']);
        $this->assertEquals(500, $info['total_consumed']);
    }

    // ---------- 积分充值 ----------

    public function test_recharge_increases_balance(): void
    {
        $service = app(TenantCreditService::class);

        $result = $service->recharge(1001, 2001, 500, 'wechat', 'Test recharge');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['transaction']);

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();
        $this->assertEquals(500, $account->balance);
        $this->assertEquals(500, $account->total_recharged);

        $transaction = CreditTransaction::where('account_id', $account->credit_account_id)
            ->where('type', 'recharge')
            ->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(500, $transaction->amount);
        $this->assertEquals(500, $transaction->balance_after);
    }

    public function test_recharge_rejects_invalid_amount(): void
    {
        $service = app(TenantCreditService::class);

        $result = $service->recharge(1001, 2001, 0, 'wechat');
        $this->assertFalse($result['success']);

        $result = $service->recharge(1001, 2001, -100, 'wechat');
        $this->assertFalse($result['success']);
    }

    public function test_recharge_accumulates_balance(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 500, 'wechat');
        $service->recharge(1001, 2001, 300, 'alipay');

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();
        $this->assertEquals(800, $account->balance);
        $this->assertEquals(800, $account->total_recharged);
    }

    // ---------- 积分消费 ----------

    public function test_consume_decreases_balance(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 1000, 'wechat');

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();

        $transaction = $account->consume(300, 'api_call', 'req-001', 'API consumption');

        $this->assertEquals(-300, $transaction->amount);
        $this->assertEquals(700, $transaction->balance_after);

        $account->refresh();
        $this->assertEquals(700, $account->balance);
        $this->assertEquals(300, $account->total_consumed);
    }

    public function test_consume_throws_when_insufficient_balance(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 100, 'wechat');

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();

        $this->expectException(\Exception::class);
        $account->consume(200);
    }

    // ---------- 积分退款 ----------

    public function test_refund_increases_balance(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 1000, 'wechat');

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();

        $account->consume(300);
        $transaction = $account->refund(100, 'Refund for overcharge');

        $this->assertEquals(100, $transaction->amount);

        $account->refresh();
        $this->assertEquals(800, $account->balance);
        $this->assertEquals(200, $account->total_consumed);
    }

    // ---------- 积分赠送 ----------

    public function test_gift_increases_balance(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 500, 'wechat');

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();

        $transaction = $account->gift(2001, 200, 30, 'Welcome gift');

        $this->assertEquals(200, $transaction->amount);
        $this->assertEquals('gift', $transaction->type);
        $this->assertNotNull($transaction->expires_at);

        $account->refresh();
        $this->assertEquals(700, $account->balance);
    }

    // ---------- 余额预警 ----------

    public function test_get_balance_alert_returns_no_alert_when_sufficient(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 50000, 'wechat');

        $alert = $service->getBalanceAlert(1001, 10000);

        $this->assertFalse($alert['alert']);
        $this->assertEquals(50000, $alert['balance']);
    }

    public function test_get_balance_alert_triggers_when_low(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 5000, 'wechat');

        $alert = $service->getBalanceAlert(1001, 10000);

        $this->assertTrue($alert['alert']);
        $this->assertEquals(5000, $alert['balance']);
    }

    public function test_get_balance_alert_returns_no_alert_when_no_account(): void
    {
        $service = app(TenantCreditService::class);

        $alert = $service->getBalanceAlert(1001, 10000);

        $this->assertFalse($alert['alert']);
        $this->assertEquals(0, $alert['balance']);
    }

    // ---------- 消费趋势 ----------

    public function test_get_consume_trend_returns_empty_without_account(): void
    {
        Tenant::create(['tenant_id' => 1002, 'name' => 'No Account', 'slug' => 'no-account', 'status' => 'active']);

        $service = app(TenantCreditService::class);

        $trend = $service->getConsumeTrend(1002);

        $this->assertIsArray($trend);
        $this->assertEmpty($trend);
    }

    public function test_get_consume_trend_returns_data_with_transactions(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 10000, 'wechat');

        $account = CreditAccount::where('tenant_id', '1001')
            ->where('account_type', 'enterprise')
            ->first();
        $account->consume(100, 'test');

        $trend = $service->getConsumeTrend(1001);

        $this->assertIsArray($trend);
        $this->assertNotEmpty($trend);
        $todayEntry = collect($trend)->firstWhere('date', now()->format('Y-m-d'));
        $this->assertNotNull($todayEntry);
        $this->assertEquals(100, $todayEntry['amount']);
    }

    // ---------- 充值记录查询 ----------

    public function test_get_recharge_records_returns_paginated(): void
    {
        $service = app(TenantCreditService::class);

        $service->recharge(1001, 2001, 500, 'wechat');
        $service->recharge(1001, 2001, 300, 'alipay');

        $records = $service->getRechargeRecords(1001);

        $this->assertEquals(2, $records->total());
    }

    public function test_get_recharge_records_returns_empty_without_account(): void
    {
        Tenant::create(['tenant_id' => 1002, 'name' => 'Empty', 'slug' => 'empty', 'status' => 'active']);

        $service = app(TenantCreditService::class);

        $records = $service->getRechargeRecords(1002);

        $this->assertEquals(0, $records->total());
    }
}
