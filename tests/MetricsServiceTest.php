<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\MetricsSnapshot;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\MetricsService;

/**
 * MetricsService 单元测试
 *
 * 覆盖：请求采样、P50/P95/P99 计算、快照采集、错误率、端点分布、聚合上卷、租户隔离
 */
class MetricsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 冻结时间，避免跨分钟边界导致的样本过滤问题
        Carbon::setTestNow('2026-06-29 12:00:30');

        Tenant::create(['tenant_id' => 1001, 'name' => 'Metrics Tenant', 'slug' => 'metrics-tenant', 'status' => 'active']);

        TenantContext::setTenantId('1001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- 请求采样 ----------

    public function test_record_request_stores_sample_in_cache(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);

        $qps = $service->getQps(60);

        $this->assertGreaterThan(0, $qps);
    }

    public function test_record_request_with_explicit_tenant_id(): void
    {
        $service = app(MetricsService::class);

        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant 2', 'slug' => 't2', 'status' => 'active']);

        $service->recordRequest('/api/v1/test', 100.0, 200, 1002);

        $dist = $service->getEndpointDistribution(5);
        $this->assertEquals(1, $dist['/api/v1/test']);
    }

    // ---------- P50/P95/P99 计算 ----------

    public function test_calculate_percentiles_with_empty_array(): void
    {
        $service = app(MetricsService::class);

        $result = $service->calculatePercentiles([]);

        $this->assertNull($result['p50']);
        $this->assertNull($result['p95']);
        $this->assertNull($result['p99']);
    }

    public function test_calculate_percentiles_single_sample(): void
    {
        $service = app(MetricsService::class);

        $result = $service->calculatePercentiles([42.0]);

        $this->assertEquals(42.0, $result['p50']);
        $this->assertEquals(42.0, $result['p95']);
        $this->assertEquals(42.0, $result['p99']);
    }

    public function test_calculate_percentiles_p50_p95_p99_correct(): void
    {
        $service = app(MetricsService::class);

        // 1..100 sorted
        $latencies = range(1, 100);
        $result = $service->calculatePercentiles($latencies);

        // count=100, index=floor((count-1)*p)
        // p50: floor(99*0.5)=49 -> value[49]=50
        // p95: floor(99*0.95)=94 -> value[94]=95
        // p99: floor(99*0.99)=98 -> value[98]=99
        $this->assertEquals(50.0, $result['p50']);
        $this->assertEquals(95.0, $result['p95']);
        $this->assertEquals(99.0, $result['p99']);
    }

    public function test_calculate_percentiles_with_ten_samples(): void
    {
        $service = app(MetricsService::class);

        // 10, 20, ..., 100
        $latencies = [];
        for ($i = 1; $i <= 10; $i++) {
            $latencies[] = $i * 10.0;
        }
        $result = $service->calculatePercentiles($latencies);

        // count=10
        // p50: floor(9*0.5)=4 -> value[4]=50
        // p95: floor(9*0.95)=8 -> value[8]=90
        // p99: floor(9*0.99)=8 -> value[8]=90
        $this->assertEquals(50.0, $result['p50']);
        $this->assertEquals(90.0, $result['p95']);
        $this->assertEquals(90.0, $result['p99']);
    }

    // ---------- 快照采集 ----------

    public function test_collect_snapshot_writes_request_count_metric(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);
        $service->recordRequest('/api/v1/test', 200.0, 200);
        $service->recordRequest('/api/v1/test', 300.0, 500);

        $count = $service->collectSnapshot();

        $this->assertGreaterThan(0, $count);

        $req = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_REQUESTS)
            ->where('tenant_id', 1001)
            ->first();

        $this->assertNotNull($req);
        $this->assertEquals(3.0, $req->metric_value);
        $this->assertEquals('minute', $req->granularity);
        $this->assertEquals(0, $req->aggregated);
    }

    public function test_collect_snapshot_writes_latency_percentiles(): void
    {
        $service = app(MetricsService::class);

        for ($i = 1; $i <= 10; $i++) {
            $service->recordRequest('/api/v1/test', $i * 10.0, 200);
        }

        $service->collectSnapshot();

        $p50 = DB::table('metrics_snapshots')->where('metric_name', 'latency_p50')->where('tenant_id', 1001)->first();
        $p95 = DB::table('metrics_snapshots')->where('metric_name', 'latency_p95')->where('tenant_id', 1001)->first();
        $p99 = DB::table('metrics_snapshots')->where('metric_name', 'latency_p99')->where('tenant_id', 1001)->first();

        $this->assertNotNull($p50);
        $this->assertNotNull($p95);
        $this->assertNotNull($p99);
        $this->assertEquals(50.0, $p50->metric_value);
        $this->assertEquals(90.0, $p95->metric_value);
        $this->assertEquals(90.0, $p99->metric_value);
    }

    public function test_collect_snapshot_writes_error_rate(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);
        $service->recordRequest('/api/v1/test', 100.0, 200);
        $service->recordRequest('/api/v1/test', 100.0, 500);

        $service->collectSnapshot();

        $err = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_ERROR_RATE)
            ->where('tenant_id', 1001)
            ->first();

        $this->assertNotNull($err);
        $this->assertGreaterThan(33.0, $err->metric_value);
        $this->assertLessThan(34.0, $err->metric_value);
    }

    public function test_collect_snapshot_writes_endpoint_distribution(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/users', 100.0, 200);
        $service->recordRequest('/api/v1/users', 100.0, 200);
        $service->recordRequest('/api/v1/orders', 100.0, 200);

        $service->collectSnapshot();

        $endpoints = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_API_ENDPOINT)
            ->where('tenant_id', 1001)
            ->get();

        $this->assertEquals(2, $endpoints->count());

        $byValue = $endpoints->pluck('metric_value', 'dimension_value')->all();
        $this->assertEquals(2.0, $byValue['/api/v1/users']);
        $this->assertEquals(1.0, $byValue['/api/v1/orders']);
    }

    public function test_collect_snapshot_writes_active_tenants_metric(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);

        $service->collectSnapshot();

        $at = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_ACTIVE_TENANTS)
            ->whereNull('tenant_id')
            ->first();

        $this->assertNotNull($at);
        $this->assertEquals(1.0, $at->metric_value);
    }

    public function test_collect_snapshot_returns_zero_when_no_samples(): void
    {
        $service = app(MetricsService::class);

        $count = $service->collectSnapshot();

        // 仅 active_tenants/active_users 系统级快照，无租户请求快照
        $this->assertGreaterThan(0, $count);

        $req = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_REQUESTS)
            ->where('tenant_id', 1001)
            ->first();
        $this->assertNull($req);
    }

    // ---------- QPS / RPM / 错误率 ----------

    public function test_get_qps_with_no_data(): void
    {
        $service = app(MetricsService::class);

        $this->assertEquals(0.0, $service->getQps(60));
    }

    public function test_get_qps_with_samples(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);
        $service->recordRequest('/api/v1/test', 200.0, 200);
        $service->recordRequest('/api/v1/test', 300.0, 200);

        $qps = $service->getQps(60);

        $this->assertGreaterThan(0, $qps);
    }

    public function test_get_rpm_with_samples(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);

        $rpm = $service->getRpm(1);

        $this->assertGreaterThan(0, $rpm);
    }

    public function test_get_error_rate_with_no_data(): void
    {
        $service = app(MetricsService::class);

        $this->assertEquals(0.0, $service->getErrorRate(60));
    }

    public function test_get_error_rate_with_errors(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);
        $service->recordRequest('/api/v1/test', 100.0, 500);
        $service->recordRequest('/api/v1/test', 100.0, 500);

        $rate = $service->getErrorRate(60);

        $this->assertGreaterThan(66.0, $rate);
        $this->assertLessThan(67.0, $rate);
    }

    public function test_get_error_rate_zero_when_all_success(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);
        $service->recordRequest('/api/v1/test', 100.0, 200);

        $this->assertEquals(0.0, $service->getErrorRate(60));
    }

    // ---------- 端点分布 ----------

    public function test_get_endpoint_distribution(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/users', 100.0, 200);
        $service->recordRequest('/api/v1/users', 100.0, 200);
        $service->recordRequest('/api/v1/orders', 100.0, 200);

        $dist = $service->getEndpointDistribution(5);

        $this->assertEquals(2, $dist['/api/v1/users']);
        $this->assertEquals(1, $dist['/api/v1/orders']);
    }

    public function test_get_endpoint_distribution_empty(): void
    {
        $service = app(MetricsService::class);

        $this->assertEquals([], $service->getEndpointDistribution(5));
    }

    // ---------- 活跃租户/用户数 ----------

    public function test_get_active_tenants_with_samples(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);

        $this->assertEquals(1, $service->getActiveTenants(5));
    }

    public function test_get_active_tenants_without_samples(): void
    {
        $service = app(MetricsService::class);

        $this->assertEquals(0, $service->getActiveTenants(5));
    }

    public function test_get_active_users_returns_zero_without_sessions(): void
    {
        $service = app(MetricsService::class);

        $this->assertEquals(0, $service->getActiveUsers(5));
    }

    // ---------- 聚合上卷 ----------

    public function test_aggregate_minute_to_hour_sums_count_metric(): void
    {
        $service = app(MetricsService::class);
        $idGen = app(IdGeneratorContract::class);
        $hourStart = Carbon::now()->startOfHour();
        $tenantId = 1001;

        $rows = [];
        foreach ([10.0, 20.0, 30.0] as $i => $value) {
            $rows[] = [
                'metrics_snapshot_id' => $idGen->generate(),
                'tenant_id' => $tenantId,
                'metric_name' => MetricsSnapshot::METRIC_REQUESTS,
                'metric_value' => $value,
                'dimension_type' => null,
                'dimension_value' => null,
                'granularity' => MetricsSnapshot::GRANULARITY_MINUTE,
                'aggregated' => false,
                'sampled_at' => $hourStart->copy()->addMinutes($i + 1),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('metrics_snapshots')->insert($rows);

        $written = $service->aggregate(
            MetricsSnapshot::GRANULARITY_MINUTE,
            MetricsSnapshot::GRANULARITY_HOUR,
            $hourStart
        );

        $this->assertEquals(1, $written);

        $hourRow = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_REQUESTS)
            ->where('granularity', MetricsSnapshot::GRANULARITY_HOUR)
            ->where('tenant_id', $tenantId)
            ->first();

        $this->assertNotNull($hourRow);
        $this->assertEquals(60.0, $hourRow->metric_value); // 10+20+30
        $this->assertEquals(1, $hourRow->aggregated);

        // 源数据被标记为已聚合
        $remaining = DB::table('metrics_snapshots')
            ->where('granularity', MetricsSnapshot::GRANULARITY_MINUTE)
            ->where('aggregated', false)
            ->where('tenant_id', $tenantId)
            ->count();
        $this->assertEquals(0, $remaining);
    }

    public function test_aggregate_averages_latency_metric(): void
    {
        $service = app(MetricsService::class);
        $idGen = app(IdGeneratorContract::class);
        $hourStart = Carbon::now()->startOfHour();
        $tenantId = 1001;

        DB::table('metrics_snapshots')->insert([
            [
                'metrics_snapshot_id' => $idGen->generate(),
                'tenant_id' => $tenantId,
                'metric_name' => 'latency_p95',
                'metric_value' => 100.0,
                'dimension_type' => null,
                'dimension_value' => null,
                'granularity' => MetricsSnapshot::GRANULARITY_MINUTE,
                'aggregated' => false,
                'sampled_at' => $hourStart->copy()->addMinute(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'metrics_snapshot_id' => $idGen->generate(),
                'tenant_id' => $tenantId,
                'metric_name' => 'latency_p95',
                'metric_value' => 200.0,
                'dimension_type' => null,
                'dimension_value' => null,
                'granularity' => MetricsSnapshot::GRANULARITY_MINUTE,
                'aggregated' => false,
                'sampled_at' => $hourStart->copy()->addMinutes(2),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $written = $service->aggregate(
            MetricsSnapshot::GRANULARITY_MINUTE,
            MetricsSnapshot::GRANULARITY_HOUR,
            $hourStart
        );

        $this->assertEquals(1, $written);

        $hourRow = DB::table('metrics_snapshots')
            ->where('metric_name', 'latency_p95')
            ->where('granularity', MetricsSnapshot::GRANULARITY_HOUR)
            ->where('tenant_id', $tenantId)
            ->first();

        $this->assertNotNull($hourRow);
        $this->assertEquals(150.0, $hourRow->metric_value); // (100+200)/2
    }

    public function test_aggregate_returns_zero_when_no_source_data(): void
    {
        $service = app(MetricsService::class);

        $written = $service->aggregate(
            MetricsSnapshot::GRANULARITY_MINUTE,
            MetricsSnapshot::GRANULARITY_HOUR,
            Carbon::now()->startOfHour()
        );

        $this->assertEquals(0, $written);
    }

    // ---------- storeSnapshot ----------

    public function test_store_snapshot_inserts_row(): void
    {
        $service = app(MetricsService::class);

        $service->storeSnapshot(
            metric: 'custom_metric',
            value: 42.5,
            granularity: MetricsSnapshot::GRANULARITY_MINUTE,
            sampledAt: now(),
            tenantId: 1001
        );

        $row = DB::table('metrics_snapshots')
            ->where('metric_name', 'custom_metric')
            ->where('tenant_id', 1001)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(42.5, $row->metric_value);
    }

    // ---------- 时序查询 ----------

    public function test_get_series_returns_ordered_points(): void
    {
        $service = app(MetricsService::class);
        $idGen = app(IdGeneratorContract::class);
        $base = Carbon::now()->startOfHour();

        for ($i = 1; $i <= 3; $i++) {
            DB::table('metrics_snapshots')->insert([
                'metrics_snapshot_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'metric_name' => 'requests',
                'metric_value' => $i * 10.0,
                'dimension_type' => null,
                'dimension_value' => null,
                'granularity' => MetricsSnapshot::GRANULARITY_HOUR,
                'aggregated' => true,
                'sampled_at' => $base->copy()->addHours($i),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $series = $service->getSeries('requests', MetricsSnapshot::GRANULARITY_HOUR, 3);

        $this->assertEquals(3, $series->count());
        $values = $series->pluck('metric_value')->all();
        $this->assertEquals([10.0, 20.0, 30.0], $values);
    }

    // ---------- 租户隔离 ----------

    public function test_metrics_are_isolated_by_tenant(): void
    {
        $service = app(MetricsService::class);

        $service->recordRequest('/api/v1/test', 100.0, 200);

        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant 2', 'slug' => 't2', 'status' => 'active']);
        TenantContext::setTenantId('1002');

        $service->recordRequest('/api/v1/test', 200.0, 200);
        $service->recordRequest('/api/v1/test', 200.0, 200);

        $service->collectSnapshot();

        $t1 = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_REQUESTS)
            ->where('tenant_id', 1001)
            ->first();
        $t2 = DB::table('metrics_snapshots')
            ->where('metric_name', MetricsSnapshot::METRIC_REQUESTS)
            ->where('tenant_id', 1002)
            ->first();

        $this->assertNotNull($t1);
        $this->assertNotNull($t2);
        $this->assertEquals(1.0, $t1->metric_value);
        $this->assertEquals(2.0, $t2->metric_value);
    }
}
