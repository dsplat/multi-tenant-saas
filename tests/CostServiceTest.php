<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Billing\Models\CostAllocation;
use MultiTenantSaas\Modules\Billing\Services\CostService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\MiscModule;

/**
 * CostService 单元测试
 *
 * 覆盖：基础设施成本分摊、AI 用量成本归入、第三方服务成本、
 * 租户盈亏分析、成本趋势预测、月度成本报表、租户隔离
 */
class CostServiceTest extends TestCase
{
    protected array $uses = [AiModule::class, BillingModule::class, MiscModule::class];

    protected ?CostService $service = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');

        Tenant::create(['tenant_id' => 1001, 'name' => 'Cost Tenant', 'slug' => 'cost-tenant', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        TenantContext::setTenantId('1001');

        $this->service = app(CostService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- 基础设施成本分摊 ----------

    public function test_allocate_infrastructure_cost_creates_record(): void
    {
        $allocation = $this->service->allocateInfrastructureCost(
            subtype: CostAllocation::SUBTYPE_COMPUTE,
            amount: 500.50,
            period: '2026-06',
            basis: 'by_users',
            basisValue: 10,
        );

        $this->assertNotNull($allocation->cost_allocation_id);
        $this->assertEquals(CostAllocation::TYPE_INFRASTRUCTURE, $allocation->cost_type);
        $this->assertEquals(CostAllocation::SUBTYPE_COMPUTE, $allocation->cost_subtype);
        $this->assertEquals(500.50, $allocation->amount);
        $this->assertEquals('2026-06', $allocation->period);
        $this->assertEquals(1001, $allocation->tenant_id);

        $row = DB::table('cost_allocations')->where('cost_allocation_id', $allocation->cost_allocation_id)->first();
        $this->assertNotNull($row);
        $this->assertEquals('by_users', $row->allocation_basis);
    }

    public function test_allocate_infrastructure_cost_clamps_negative_amount(): void
    {
        $allocation = $this->service->allocateInfrastructureCost(
            subtype: CostAllocation::SUBTYPE_STORAGE,
            amount: -100.0,
            period: '2026-06',
            basis: 'by_storage',
        );

        $this->assertEquals(0.0, $allocation->amount);
    }

    // ---------- AI 用量成本归入 ----------

    public function test_allocate_ai_cost_aggregates_from_ai_requests(): void
    {
        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        DB::table('ai_requests')->insert([
            [
                'request_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'user_id' => null,
                'model' => 'gpt-4',
                'provider' => 'openai',
                'input_tokens' => 100,
                'output_tokens' => 200,
                'cost' => 1.50,
                'status' => 'success',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'request_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'user_id' => null,
                'model' => 'gpt-4',
                'provider' => 'openai',
                'input_tokens' => 50,
                'output_tokens' => 50,
                'cost' => 0.75,
                'status' => 'success',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'request_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'user_id' => null,
                'model' => 'claude-3',
                'provider' => 'anthropic',
                'input_tokens' => 80,
                'output_tokens' => 120,
                'cost' => 2.00,
                'status' => 'failed',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $allocation = $this->service->allocateAiCost();

        $this->assertNotNull($allocation);
        $this->assertEquals(CostAllocation::TYPE_AI_USAGE, $allocation->cost_type);
        $this->assertEquals(1001, $allocation->tenant_id);
        $this->assertEquals(2.25, round((float) $allocation->amount, 4)); // 1.50 + 0.75，排除 failed

        $metadata = $allocation->metadata;
        $this->assertNotEmpty($metadata['by_model']);
    }

    public function test_allocate_ai_cost_returns_null_when_no_cost(): void
    {
        $allocation = $this->service->allocateAiCost();

        $this->assertNull($allocation);
    }

    public function test_allocate_ai_cost_isolates_by_tenant(): void
    {
        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        DB::table('ai_requests')->insert([
            'request_id' => $idGen->generate(),
            'tenant_id' => 1002,
            'user_id' => null,
            'model' => 'gpt-4',
            'provider' => 'openai',
            'input_tokens' => 100,
            'output_tokens' => 100,
            'cost' => 3.00,
            'status' => 'success',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 当前上下文为 1001，不应聚合 1002 的成本
        $allocation = $this->service->allocateAiCost();

        $this->assertNull($allocation);
    }

    // ---------- 第三方服务成本 ----------

    public function test_allocate_third_party_cost_creates_record(): void
    {
        $allocation = $this->service->allocateThirdPartyCost(
            service: 'stripe',
            amount: 30.00,
            period: '2026-06',
            basis: 'by_transactions',
            basisValue: 100,
        );

        $this->assertEquals(CostAllocation::TYPE_THIRD_PARTY, $allocation->cost_type);
        $this->assertEquals('stripe', $allocation->cost_subtype);
        $this->assertEquals(30.00, $allocation->amount);
    }

    // ---------- 租户级盈亏分析 ----------

    public function test_get_profit_loss_calculates_correctly(): void
    {
        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        // 收入：10000
        DB::table('financial_records')->insert([
            'financial_record_id' => $idGen->generate(),
            'tenant_id' => 1001,
            'type' => 'income',
            'amount' => 10000,
            'status' => 'paid',
            'paid_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 成本：基础设施 2000 + 第三方 500 = 2500
        $this->service->allocateInfrastructureCost('compute', 2000.0, '2026-06', 'by_users');
        $this->service->allocateThirdPartyCost('stripe', 500.0, '2026-06', 'by_transactions');

        $result = $this->service->getProfitLoss('2026-06');

        $this->assertEquals('2026-06', $result['period']);
        $this->assertEquals(10000.0, $result['revenue']);
        $this->assertEquals(2500.0, $result['total_cost']);
        $this->assertEquals(7500.0, $result['profit']);
        $this->assertArrayHasKey(CostAllocation::TYPE_INFRASTRUCTURE, $result['cost_breakdown']);
        $this->assertArrayHasKey(CostAllocation::TYPE_THIRD_PARTY, $result['cost_breakdown']);
    }

    public function test_get_profit_loss_zero_when_no_data(): void
    {
        $result = $this->service->getProfitLoss('2026-06');

        $this->assertEquals(0.0, $result['revenue']);
        $this->assertEquals(0.0, $result['total_cost']);
        $this->assertEquals(0.0, $result['profit']);
    }

    // ---------- 成本趋势预测 ----------

    public function test_forecast_cost_trend_with_history(): void
    {
        $idGen = app(IdGeneratorContract::class);

        // 插入 6 个月历史成本：100, 200, 300, 400, 500, 600
        $amounts = [100.0, 200.0, 300.0, 400.0, 500.0, 600.0];
        for ($i = 5; $i >= 0; $i--) {
            $period = now()->subMonths($i)->format('Y-m');
            DB::table('cost_allocations')->insert([
                'cost_allocation_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'cost_type' => CostAllocation::TYPE_INFRASTRUCTURE,
                'cost_subtype' => 'compute',
                'amount' => $amounts[5 - $i],
                'currency' => 'CNY',
                'period' => $period,
                'allocation_basis' => 'by_users',
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
        }

        $result = $this->service->forecastCostTrend(3);

        $this->assertCount(6, $result['history']);
        $this->assertCount(3, $result['forecast']);
        $this->assertGreaterThan(0, $result['avg_monthly_cost']);
        // 线性增长趋势，增长率 > 0
        $this->assertGreaterThan(0, $result['growth_rate']);
        // 预测值应递增（线性回归斜率为正）
        $this->assertGreaterThan(
            $result['forecast'][0]['cost'],
            $result['forecast'][2]['cost'],
        );
    }

    public function test_forecast_cost_trend_empty_returns_zeros(): void
    {
        $result = $this->service->forecastCostTrend(2);

        $this->assertCount(2, $result['forecast']);
        $this->assertEquals(0.0, $result['forecast'][0]['cost']);
        $this->assertEquals(0.0, $result['avg_monthly_cost']);
    }

    // ---------- 月度成本报表 ----------

    public function test_get_monthly_report_aggregates_by_type(): void
    {
        $this->service->allocateInfrastructureCost('compute', 1000.0, '2026-06', 'by_users');
        $this->service->allocateInfrastructureCost('storage', 500.0, '2026-06', 'by_storage');
        $this->service->allocateThirdPartyCost('stripe', 200.0, '2026-06', 'by_transactions');

        $report = $this->service->getMonthlyReport('2026-06');

        $this->assertEquals('2026-06', $report['period']);
        $this->assertEquals(1700.0, $report['total']);
        $this->assertEquals(1500.0, $report['by_type'][CostAllocation::TYPE_INFRASTRUCTURE]);
        $this->assertEquals(200.0, $report['by_type'][CostAllocation::TYPE_THIRD_PARTY]);
        $this->assertEquals(3, $report['records']);
        $this->assertArrayHasKey('compute', $report['by_subtype']);
        $this->assertArrayHasKey('storage', $report['by_subtype']);
    }

    public function test_get_monthly_report_empty_period(): void
    {
        $report = $this->service->getMonthlyReport('2025-01');

        $this->assertEquals(0.0, $report['total']);
        $this->assertEquals(0, $report['records']);
    }

    // ---------- 租户隔离 ----------

    public function test_cost_allocations_isolated_by_tenant(): void
    {
        $this->service->allocateInfrastructureCost('compute', 1000.0, '2026-06', 'by_users');

        TenantContext::setTenantId('1002');
        $this->service->allocateInfrastructureCost('compute', 2000.0, '2026-06', 'by_users');

        $report1 = $this->service->getMonthlyReport('2026-06', 1001);
        $report2 = $this->service->getMonthlyReport('2026-06', 1002);

        $this->assertEquals(1000.0, $report1['total']);
        $this->assertEquals(2000.0, $report2['total']);
    }
}
