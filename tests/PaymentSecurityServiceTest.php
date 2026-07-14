<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Billing\Services\PaymentSecurityService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\BillingModule;

/**
 * PaymentSecurityService 单元测试
 *
 * 覆盖：支付密码设置/验证、支付限额检查、支付日志记录、风控检查
 */
class PaymentSecurityServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            User::create([
                'user_id' => 2001,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'password' => Hash::make('secret'),
            ]);
        });

        TenantContext::setTenantId('1001');
    }

    /**
     * 预插入支付密码记录
     *
     * PaymentSecurityService::setPaymentPassword 使用 updateOrInsert，
     * 其 created_at 字段使用了 DB::raw('COALESCE(created_at, NOW())')，
     * 在 SQLite 的 INSERT 上下文中会失败，故预先插入记录使其走 UPDATE 路径。
     */
    private function preInsertPaymentPassword(int $userId, int $tenantId): void
    {
        DB::table('user_payment_passwords')->insert([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'password_hash' => Hash::make('placeholder'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 直接通过 DB 插入支付订单（绕过模型 fillable 限制，包含 user_id）
     */
    private function createPaymentOrder(array $data): void
    {
        DB::table('payment_orders')->insert(array_merge([
            'tenant_id' => 1001,
            'user_id' => 2001,
            'driver' => 'wechat',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $data));
    }

    // ---------- 支付密码 ----------

    public function test_set_payment_password_throws_when_feature_disabled(): void
    {
        config(['pay.security.payment_password_enabled' => false]);

        $service = app(PaymentSecurityService::class);

        $this->expectException(\RuntimeException::class);
        $service->setPaymentPassword(2001, '123456');
    }

    public function test_set_payment_password_throws_when_too_short(): void
    {
        config(['pay.security.payment_password_enabled' => true]);

        $service = app(PaymentSecurityService::class);

        $this->expectException(\RuntimeException::class);
        $service->setPaymentPassword(2001, '123');
    }

    public function test_set_and_verify_payment_password_with_correct_password(): void
    {
        config(['pay.security.payment_password_enabled' => true]);

        $this->preInsertPaymentPassword(2001, 1001);

        $service = app(PaymentSecurityService::class);

        $service->setPaymentPassword(2001, '123456');

        $this->assertTrue($service->verifyPaymentPassword(2001, '123456'));

        $record = DB::table('user_payment_passwords')
            ->where('user_id', 2001)
            ->where('tenant_id', 1001)
            ->first();
        $this->assertNotNull($record);
        $this->assertNotEmpty($record->password_hash);
    }

    public function test_verify_payment_password_with_wrong_password(): void
    {
        config(['pay.security.payment_password_enabled' => true]);

        $this->preInsertPaymentPassword(2001, 1001);

        $service = app(PaymentSecurityService::class);

        $service->setPaymentPassword(2001, '123456');

        $this->assertFalse($service->verifyPaymentPassword(2001, 'wrong'));
    }

    public function test_verify_payment_password_returns_true_when_feature_disabled(): void
    {
        config(['pay.security.payment_password_enabled' => false]);

        $service = app(PaymentSecurityService::class);

        $this->assertTrue($service->verifyPaymentPassword(2001, 'anything'));
    }

    public function test_verify_payment_password_returns_false_when_no_record(): void
    {
        config(['pay.security.payment_password_enabled' => true]);

        $service = app(PaymentSecurityService::class);

        $this->assertFalse($service->verifyPaymentPassword(2001, '123456'));
    }

    // ---------- 支付限额 ----------

    public function test_per_payment_limit_passes_when_no_limit(): void
    {
        config(['pay.security.per_payment_limit' => 0]);

        $service = app(PaymentSecurityService::class);

        $this->assertTrue($service->checkPerPaymentLimit(999999));
    }

    public function test_per_payment_limit_passes_within_limit(): void
    {
        config(['pay.security.per_payment_limit' => 5000]);

        $service = app(PaymentSecurityService::class);

        $this->assertTrue($service->checkPerPaymentLimit(5000));
    }

    public function test_per_payment_limit_rejects_over_limit(): void
    {
        config(['pay.security.per_payment_limit' => 5000]);

        $service = app(PaymentSecurityService::class);

        $this->assertFalse($service->checkPerPaymentLimit(5000.01));
    }

    public function test_daily_limit_passes_when_no_limit(): void
    {
        config(['pay.security.daily_payment_limit' => 0]);

        $service = app(PaymentSecurityService::class);

        $this->assertTrue($service->checkDailyLimit(2001, 999999));
    }

    public function test_daily_limit_passes_within_limit(): void
    {
        config(['pay.security.daily_payment_limit' => 10000]);

        $this->createPaymentOrder([
            'order_no' => 'ORD-001',
            'amount' => 3000,
            'status' => 'paid',
        ]);

        $service = app(PaymentSecurityService::class);

        $this->assertTrue($service->checkDailyLimit(2001, 5000));
    }

    public function test_daily_limit_rejects_over_limit(): void
    {
        config(['pay.security.daily_payment_limit' => 5000]);

        $this->createPaymentOrder([
            'order_no' => 'ORD-002',
            'amount' => 4000,
            'status' => 'paid',
        ]);

        $service = app(PaymentSecurityService::class);

        $this->assertFalse($service->checkDailyLimit(2001, 2000));
    }

    // ---------- 支付日志 ----------

    public function test_log_payment_attempt_records_success(): void
    {
        $service = app(PaymentSecurityService::class);

        $service->logPaymentAttempt(2001, 'ORD-S01', 100.50, 'success', ['method' => 'wechat']);

        $log = DB::table('payment_logs')
            ->where('order_no', 'ORD-S01')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(1001, $log->tenant_id);
        $this->assertEquals(2001, $log->user_id);
        $this->assertEquals('success', $log->status);
        $this->assertEquals('100.50', $log->amount);

        $context = json_decode($log->context, true);
        $this->assertEquals('wechat', $context['method']);
    }

    public function test_log_payment_attempt_records_failure(): void
    {
        $service = app(PaymentSecurityService::class);

        $service->logPaymentAttempt(2001, 'ORD-F01', 50.00, 'failed', ['reason' => 'insufficient_funds']);

        $log = DB::table('payment_logs')
            ->where('order_no', 'ORD-F01')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('failed', $log->status);
    }

    // ---------- 风控检查 ----------

    public function test_check_risk_allows_normal_request(): void
    {
        config([
            'pay.security.risk_failure_threshold' => 5,
            'pay.security.risk_cooldown_sec' => 1800,
        ]);

        $service = app(PaymentSecurityService::class);

        $result = $service->checkRisk(2001);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
        $this->assertEquals(0, $result['retry_after_sec']);
    }

    public function test_check_risk_blocks_high_frequency_failures(): void
    {
        config([
            'pay.security.risk_failure_threshold' => 3,
            'pay.security.risk_cooldown_sec' => 1800,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->createPaymentOrder([
                'order_no' => "ORD-FAIL-{$i}",
                'amount' => 100,
                'status' => 'failed',
            ]);
        }

        $service = app(PaymentSecurityService::class);

        $result = $service->checkRisk(2001);

        $this->assertFalse($result['allowed']);
        $this->assertNotNull($result['reason']);
        $this->assertEquals(1800, $result['retry_after_sec']);
    }

    public function test_check_risk_remains_blocked_during_cooldown(): void
    {
        config([
            'pay.security.risk_failure_threshold' => 2,
            'pay.security.risk_cooldown_sec' => 3600,
        ]);

        for ($i = 0; $i < 2; $i++) {
            $this->createPaymentOrder([
                'order_no' => "ORD-BLK-{$i}",
                'amount' => 100,
                'status' => 'failed',
            ]);
        }

        $service = app(PaymentSecurityService::class);

        $first = $service->checkRisk(2001);
        $this->assertFalse($first['allowed']);

        $second = $service->checkRisk(2001);
        $this->assertFalse($second['allowed']);
        $this->assertGreaterThan(0, $second['retry_after_sec']);
    }

    // ---------- 对账 ----------

    public function test_reconcile_order_matches(): void
    {
        $this->createPaymentOrder([
            'order_no' => 'ORD-RECON-01',
            'amount' => 99.99,
            'status' => 'paid',
        ]);

        $service = app(PaymentSecurityService::class);

        $result = $service->reconcileOrder(1001, 'ORD-RECON-01', 99.99);

        $this->assertTrue($result['match']);
        $this->assertEquals(99.99, $result['framework_amount']);
        $this->assertEquals(99.99, $result['gateway_amount']);
    }

    public function test_reconcile_order_mismatch(): void
    {
        $this->createPaymentOrder([
            'order_no' => 'ORD-RECON-02',
            'amount' => 100.00,
            'status' => 'paid',
        ]);

        $service = app(PaymentSecurityService::class);

        $result = $service->reconcileOrder(1001, 'ORD-RECON-02', 50.00);

        $this->assertFalse($result['match']);
    }
}
