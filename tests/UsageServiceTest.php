<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\UsageRecord;
use MultiTenantSaas\Services\UsageService;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * UsageService 单元测试
 *
 * 覆盖：用量记录、按周期聚合、硬限制拒绝、软限制超额费、阶梯定价、与 RateLimitService 联动
 */
class UsageServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class, PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        SubscriptionPlan::unguarded(function () {
            SubscriptionPlan::create([
                'subscription_plan_id' => 1,
                'name' => 'free',
                'display_name' => 'Free Plan',
                'price_monthly' => 0,
                'is_active' => true,
                'sort_order' => 0,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 2,
                'name' => 'basic',
                'display_name' => 'Basic Plan',
                'price_monthly' => 99,
                'is_active' => true,
                'sort_order' => 1,
                'metered_price' => [
                    'limit' => 1000,
                    'overage_price' => 0.05,
                    'hard_limit' => false,
                ],
                'rate_limit_rpm' => 100,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 3,
                'name' => 'pro',
                'display_name' => 'Pro Plan',
                'price_monthly' => 299,
                'is_active' => true,
                'sort_order' => 2,
                'metered_price' => [
                    'tiers' => [
                        ['up_to' => 1000, 'price' => 0],
                        ['up_to' => 5000, 'price' => 0.05],
                        ['up_to' => null, 'price' => 0.03],
                    ],
                    'hard_limit' => false,
                ],
                'rate_limit_rpm' => 600,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 4,
                'name' => 'hard',
                'display_name' => 'Hard Limit Plan',
                'price_monthly' => 199,
                'is_active' => true,
                'sort_order' => 3,
                'metered_price' => [
                    'limit' => 1000,
                    'hard_limit' => true,
                ],
                'rate_limit_rpm' => 200,
            ]);
            SubscriptionPlan::create([
                'subscription_plan_id' => 5,
                'name' => 'tiered_hard',
                'display_name' => 'Tiered Hard Limit Plan',
                'price_monthly' => 399,
                'is_active' => true,
                'sort_order' => 4,
                'metered_price' => [
                    'tiers' => [
                        ['up_to' => 1000, 'price' => 0],
                        ['up_to' => 5000, 'price' => 0.05],
                    ],
                    'hard_limit' => true,
                ],
                'rate_limit_rpm' => 300,
            ]);
        });

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Usage Tenant',
            'slug' => 'usage-tenant',
            'status' => 'active',
            'subscription_plan' => 'basic',
            'subscription_plan_id' => 2,
        ]);

        TenantContext::setTenantId('1001');
    }

    // ---------- 用量记录 ----------

    public function test_record_creates_usage_record(): void
    {
        $record = UsageService::record(1001, 'api_calls', 100);

        $this->assertNotNull($record->usage_record_id);
        $this->assertEquals(1001, (int) $record->tenant_id);
        $this->assertEquals('api_calls', $record->metric_type);
        $this->assertEquals(100, (float) $record->value);
    }

    public function test_record_throws_for_negative_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UsageService::record(1001, 'api_calls', -1);
    }

    public function test_record_uses_current_period_when_null(): void
    {
        $record = UsageService::record(1001, 'api_calls', 10, null);

        $this->assertEquals(now()->format('Ym'), $record->period);
    }

    public function test_record_uses_provided_period(): void
    {
        $record = UsageService::record(1001, 'api_calls', 10, '202501');

        $this->assertEquals('202501', $record->period);
    }

    // ---------- 按周期聚合 ----------

    public function test_aggregate_returns_total_and_count(): void
    {
        UsageService::record(1001, 'api_calls', 100, '202501');
        UsageService::record(1001, 'api_calls', 200, '202501');

        $result = UsageService::aggregate(1001, 'api_calls', '202501');

        $this->assertEquals(300, $result['total']);
        $this->assertEquals(2, $result['count']);
        $this->assertEquals('api_calls', $result['metric']);
        $this->assertEquals('202501', $result['period']);
    }

    public function test_aggregate_returns_zero_for_no_records(): void
    {
        $result = UsageService::aggregate(1001, 'api_calls', '202501');

        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['count']);
    }

    public function test_aggregate_filters_by_metric(): void
    {
        UsageService::record(1001, 'api_calls', 100, '202501');
        UsageService::record(1001, 'storage_mb', 500, '202501');

        $result = UsageService::aggregate(1001, 'api_calls', '202501');

        $this->assertEquals(100, $result['total']);
        $this->assertEquals(1, $result['count']);
    }

    public function test_aggregate_filters_by_period(): void
    {
        UsageService::record(1001, 'api_calls', 100, '202501');
        UsageService::record(1001, 'api_calls', 200, '202502');

        $result = UsageService::aggregate(1001, 'api_calls', '202501');

        $this->assertEquals(100, $result['total']);
        $this->assertEquals(1, $result['count']);
    }

    public function test_aggregate_isolates_by_tenant(): void
    {
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
            'subscription_plan' => 'basic',
            'subscription_plan_id' => 2,
        ]);
        UsageService::record(1001, 'api_calls', 100, '202501');
        TenantContext::setTenantId('1002');
        UsageService::record(1002, 'api_calls', 300, '202501');

        TenantContext::setTenantId('1001');
        $result = UsageService::aggregate(1001, 'api_calls', '202501');

        $this->assertEquals(100, $result['total']);
    }

    // ---------- 查询 ----------

    public function test_query_returns_records_ordered_desc(): void
    {
        $this->recordUsageWithTimestamp(1001, 'api_calls', 10, '202501', now()->subSecond());
        $this->recordUsageWithTimestamp(1001, 'api_calls', 20, '202502', now());

        $records = UsageService::query(1001, 'api_calls');

        $this->assertEquals(2, $records->count());
        $this->assertEquals('202502', $records->first()->period);
    }

    public function test_query_filters_by_metric(): void
    {
        UsageService::record(1001, 'api_calls', 10, '202501');
        UsageService::record(1001, 'storage_mb', 20, '202501');

        $records = UsageService::query(1001, 'api_calls');

        $this->assertEquals(1, $records->count());
    }

    public function test_query_filters_by_period_range(): void
    {
        UsageService::record(1001, 'api_calls', 10, '202501');
        UsageService::record(1001, 'api_calls', 20, '202502');
        UsageService::record(1001, 'api_calls', 30, '202503');

        $records = UsageService::query(1001, 'api_calls', '202501', '202502');

        $this->assertEquals(2, $records->count());
    }

    // ---------- 超额判定：无规则 ----------

    public function test_check_overage_returns_allowed_when_no_rules(): void
    {
        $this->setTenantPlan(1);

        $result = UsageService::checkOverage(1001, 'api_calls', 100);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['overage']);
        $this->assertEquals(0, $result['price']);
    }

    // ---------- 超额判定：简单限额 ----------

    public function test_check_overage_returns_allowed_under_limit(): void
    {
        UsageService::record(1001, 'api_calls', 500);
        $result = UsageService::checkOverage(1001, 'api_calls', 100);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['overage']);
        $this->assertEquals(0, $result['price']);
    }

    public function test_check_overage_soft_limit_charges_overage(): void
    {
        UsageService::record(1001, 'api_calls', 900);
        $result = UsageService::checkOverage(1001, 'api_calls', 200);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(100, $result['overage']);
        $this->assertEquals(5, $result['price']);
    }

    public function test_check_overage_hard_limit_rejects(): void
    {
        $this->setTenantPlan(4);
        UsageService::record(1001, 'api_calls', 900);
        $result = UsageService::checkOverage(1001, 'api_calls', 200);

        $this->assertFalse($result['allowed']);
        $this->assertEquals(100, $result['overage']);
        $this->assertEquals(0, $result['price']);
    }

    // ---------- 超额判定：阶梯定价 ----------

    public function test_check_overage_tiered_pricing_within_free_tier(): void
    {
        $this->setTenantPlan(3);
        UsageService::record(1001, 'api_calls', 500);
        $result = UsageService::checkOverage(1001, 'api_calls', 300);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['overage']);
        $this->assertEquals(0, $result['price']);
    }

    public function test_check_overage_tiered_pricing_crosses_paid_tier(): void
    {
        $this->setTenantPlan(3);
        UsageService::record(1001, 'api_calls', 800);
        $result = UsageService::checkOverage(1001, 'api_calls', 300);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(100, $result['overage']);
        $this->assertEquals(5, $result['price']);
    }

    public function test_check_overage_tiered_hard_limit_rejects(): void
    {
        $this->setTenantPlan(5);
        UsageService::record(1001, 'api_calls', 4900);
        $result = UsageService::checkOverage(1001, 'api_calls', 200);

        $this->assertFalse($result['allowed']);
        $this->assertEquals(100, $result['overage']);
        $this->assertEquals(0, $result['price']);
    }

    // ---------- 与 RateLimitService 联动 ----------

    public function test_enforce_rate_limit_returns_positive_value(): void
    {
        $limit = UsageService::enforceRateLimit(1001);

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual(100, $limit);
    }

    public function test_enforce_rate_limit_respects_plan_rate_limit_rpm(): void
    {
        $this->setTenantPlan(3);

        $limit = UsageService::enforceRateLimit(1001);

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual(600, $limit);
    }

    public function test_enforce_rate_limit_uses_default_when_no_plan(): void
    {
        Tenant::create([
            'tenant_id' => 1003,
            'name' => 'No Plan Tenant',
            'slug' => 'no-plan-tenant',
            'status' => 'active',
            'subscription_plan' => 'unknown',
        ]);
        TenantContext::setTenantId('1003');

        $limit = UsageService::enforceRateLimit(1003);

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual(60, $limit);
    }

    // ---------- 辅助方法 ----------

    private function setTenantPlan(int $planId): void
    {
        $tenant = Tenant::find(1001);
        $tenant->subscription_plan_id = $planId;
        $tenant->subscription_plan = SubscriptionPlan::find($planId)->name;
        $tenant->save();
    }

    private function recordUsageWithTimestamp(int $tenantId, string $metric, float $value, string $period, $recordedAt): void
    {
        UsageRecord::create([
            'tenant_id' => $tenantId,
            'metric_type' => $metric,
            'value' => $value,
            'period' => $period,
            'recorded_at' => $recordedAt,
        ]);
    }
}
