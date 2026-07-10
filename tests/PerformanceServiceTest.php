<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\PerformanceService;
use MultiTenantSaas\Services\StructuredLogService;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * PerformanceService 单元测试
 *
 * 覆盖：API 响应时间记录、数据库查询记录、内存使用记录、慢请求查询、时间窗口计算验证
 */
class PerformanceServiceTest extends TestCase
{
    protected array $uses = [PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Perf Tenant', 'slug' => 'perf-tenant', 'status' => 'active']);

        TenantContext::setTenantId('1001');
    }

    // ---------- API 响应时间记录 ----------

    public function test_record_api_response_stores_metric(): void
    {
        $service = app(PerformanceService::class);

        $service->recordApiResponse('/api/v1/users', 0.250, 200);

        $aggregated = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);

        $this->assertEquals(1, $aggregated['count']);
        $this->assertEquals(250.0, $aggregated['avg']);
        $this->assertEquals(250.0, $aggregated['min']);
        $this->assertEquals(250.0, $aggregated['max']);
    }

    public function test_record_api_response_logs_to_structured_logs(): void
    {
        $service = app(PerformanceService::class);

        $service->recordApiResponse('/api/v1/test', 0.500, 200);

        $log = DB::table('structured_logs')
            ->where('category', StructuredLogService::CATEGORY_PERFORMANCE)
            ->where('action', 'api.response')
            ->first();

        $this->assertNotNull($log);
        $context = json_decode($log->context, true);
        $this->assertEquals(0.500, $context['duration_sec']);
        $this->assertEquals('/api/v1/test', $context['route']);
        $this->assertEquals(200, $context['status']);
    }

    public function test_record_multiple_api_responses_aggregates_correctly(): void
    {
        $service = app(PerformanceService::class);

        $service->recordApiResponse('/api/v1/test', 0.100, 200);  // 100ms
        $service->recordApiResponse('/api/v1/test', 0.200, 200);  // 200ms
        $service->recordApiResponse('/api/v1/test', 0.300, 500);  // 300ms

        $aggregated = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);

        $this->assertEquals(3, $aggregated['count']);
        $this->assertEquals(200.0, $aggregated['avg']);  // (100+200+300)/3 = 200
        $this->assertEquals(100.0, $aggregated['min']);
        $this->assertEquals(300.0, $aggregated['max']);
    }

    // ---------- 数据库查询记录 ----------

    public function test_record_db_queries_stores_metric(): void
    {
        $service = app(PerformanceService::class);

        $service->recordDbQueries(15, 0.050);

        $aggregated = $service->getAggregated(PerformanceService::METRIC_DB_QUERIES, 5);

        $this->assertEquals(1, $aggregated['count']);
        // incrementMetric 优先取 duration_ms（0.050s * 1000 = 50ms）
        $this->assertEquals(50.0, $aggregated['avg']);
    }

    public function test_record_db_queries_with_multiple_samples(): void
    {
        $service = app(PerformanceService::class);

        $service->recordDbQueries(10, 0.010);
        $service->recordDbQueries(20, 0.020);
        $service->recordDbQueries(30, 0.030);

        $aggregated = $service->getAggregated(PerformanceService::METRIC_DB_QUERIES, 5);

        $this->assertEquals(3, $aggregated['count']);
        $this->assertEquals(20.0, $aggregated['avg']);  // (10+20+30)/3 = 20
    }

    // ---------- 内存使用记录 ----------

    public function test_record_memory_stores_metric(): void
    {
        $service = app(PerformanceService::class);

        $service->recordMemory(104857600, 209715200);  // 100MB used, 200MB peak

        $aggregated = $service->getAggregated(PerformanceService::METRIC_MEMORY, 5);

        $this->assertEquals(1, $aggregated['count']);
        // value is used_mb = 100.00
        $this->assertEquals(100.0, $aggregated['avg']);
    }

    public function test_record_memory_with_real_values(): void
    {
        $service = app(PerformanceService::class);

        $usedBytes = memory_get_usage(true);
        $peakBytes = memory_get_peak_usage(true);

        $service->recordMemory($usedBytes, $peakBytes);

        $aggregated = $service->getAggregated(PerformanceService::METRIC_MEMORY, 5);

        $this->assertEquals(1, $aggregated['count']);
        $this->assertGreaterThan(0, $aggregated['avg']);
    }

    // ---------- CPU 使用率记录 ----------

    public function test_record_cpu_stores_metric(): void
    {
        $service = app(PerformanceService::class);

        $service->recordCpu(45.5);

        $aggregated = $service->getAggregated(PerformanceService::METRIC_CPU, 5);

        $this->assertEquals(1, $aggregated['count']);
        $this->assertEquals(45.5, $aggregated['avg']);
    }

    // ---------- 慢请求查询 ----------

    public function test_get_slow_requests_returns_empty_without_data(): void
    {
        $service = app(PerformanceService::class);

        $slow = $service->getSlowRequests(1.0);

        $this->assertTrue($slow->isEmpty());
    }

    public function test_get_slow_requests_filters_by_threshold(): void
    {
        $logService = app(StructuredLogService::class);

        // Create performance logs with different durations
        $logService->performance('fast.request', 0.100, ['route' => '/fast']);
        $logService->performance('slow.request', 2.500, ['route' => '/slow']);
        $logService->performance('very.slow.request', 5.000, ['route' => '/very-slow']);

        $service = app(PerformanceService::class);

        $slow = $service->getSlowRequests(1.0);

        $this->assertEquals(2, $slow->count());
        $actions = $slow->pluck('action')->toArray();
        $this->assertContains('slow.request', $actions);
        $this->assertContains('very.slow.request', $actions);
        $this->assertNotContains('fast.request', $actions);
    }

    public function test_get_slow_requests_respects_threshold(): void
    {
        $logService = app(StructuredLogService::class);

        $logService->performance('medium.request', 1.500, []);
        $logService->performance('very.slow.request', 5.000, []);

        $service = app(PerformanceService::class);

        $slowAt1 = $service->getSlowRequests(1.0);
        $this->assertEquals(2, $slowAt1->count());

        $slowAt3 = $service->getSlowRequests(3.0);
        $this->assertEquals(1, $slowAt3->count());
    }

    // ---------- 时间窗口计算验证 ----------

    public function test_metrics_within_same_window_are_aggregated(): void
    {
        $service = app(PerformanceService::class);

        // Record two metrics within the same 5-minute window
        $service->recordApiResponse('/api/v1/test', 0.100, 200);
        $service->recordApiResponse('/api/v1/test', 0.200, 200);

        $aggregated = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);

        $this->assertEquals(2, $aggregated['count']);
    }

    public function test_different_metrics_are_isolated(): void
    {
        $service = app(PerformanceService::class);

        $service->recordApiResponse('/api/v1/test', 0.100, 200);
        $service->recordDbQueries(15, 0.050);
        $service->recordMemory(1048576, 2097152);

        $apiStats = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);
        $dbStats = $service->getAggregated(PerformanceService::METRIC_DB_QUERIES, 5);
        $memStats = $service->getAggregated(PerformanceService::METRIC_MEMORY, 5);

        $this->assertEquals(1, $apiStats['count']);
        $this->assertEquals(1, $dbStats['count']);
        $this->assertEquals(1, $memStats['count']);
    }

    public function test_metrics_are_isolated_by_tenant(): void
    {
        $service = app(PerformanceService::class);

        $service->recordApiResponse('/api/v1/tenant1', 0.100, 200);

        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant 2', 'slug' => 'tenant-2', 'status' => 'active']);
        TenantContext::setTenantId('1002');

        $service->recordApiResponse('/api/v1/tenant2', 0.200, 200);

        $aggregated = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);

        $this->assertEquals(1, $aggregated['count'], 'Tenant 1002 should only see its own metrics');
    }

    // ---------- 概览 ----------

    public function test_get_overview_returns_all_metrics(): void
    {
        $service = app(PerformanceService::class);

        $service->recordApiResponse('/api/v1/test', 0.100, 200);
        $service->recordDbQueries(10, 0.010);
        $service->recordMemory(1048576, 2097152);
        $service->recordCpu(25.0);

        $overview = $service->getOverview();

        $this->assertArrayHasKey('api_response', $overview);
        $this->assertArrayHasKey('db_queries', $overview);
        $this->assertArrayHasKey('memory', $overview);
        $this->assertArrayHasKey('cpu', $overview);
        $this->assertArrayHasKey('generated_at', $overview);

        $this->assertEquals(1, $overview['api_response']['count']);
        $this->assertEquals(1, $overview['db_queries']['count']);
        $this->assertEquals(1, $overview['memory']['count']);
        $this->assertEquals(1, $overview['cpu']['count']);
    }

    public function test_get_overview_returns_empty_when_no_data(): void
    {
        $service = app(PerformanceService::class);

        $overview = $service->getOverview();

        $this->assertEquals(0, $overview['api_response']['count']);
        $this->assertEquals(0, $overview['db_queries']['count']);
        $this->assertEquals(0, $overview['memory']['count']);
        $this->assertEquals(0, $overview['cpu']['count']);
        $this->assertNotNull($overview['generated_at']);
    }

    // ---------- P95 计算 ----------

    public function test_p95_calculation_with_multiple_samples(): void
    {
        $service = app(PerformanceService::class);

        // Record 10 samples with increasing durations
        for ($i = 1; $i <= 10; $i++) {
            $service->recordApiResponse('/api/v1/test', $i * 0.010, 200);  // 10ms, 20ms, ..., 100ms
        }

        $aggregated = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);

        $this->assertEquals(10, $aggregated['count']);
        $this->assertEquals(55.0, $aggregated['avg']);  // (10+20+...+100)/10 = 55
        $this->assertEquals(10.0, $aggregated['min']);
        $this->assertEquals(100.0, $aggregated['max']);
        // p95 = values[(int)(10 * 0.95)] = values[9] = 100 (0-indexed, sorted)
        $this->assertEquals(100.0, $aggregated['p95']);
    }
}
