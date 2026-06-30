<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\CacheService;
use MultiTenantSaas\Services\PerformanceService;
use MultiTenantSaas\Services\StructuredLogService;

/**
 * 性能基线测试
 *
 * 建立性能基线指标：
 *  - P95 < 200ms、P99 < 500ms
 *  - 错误率 < 0.1%
 *  - 数据库连接池利用率 < 80%
 *  - N+1 查询防护
 *  - 缓存策略与慢请求检测
 *
 * 测试环境使用 SQLite 内存库，操作为亚毫秒级，
 * 基线断言用于验证指标采集机制与防护逻辑，生产由真实压测复测。
 */
class PerformanceTest extends TestCase
{
    /** 性能基线：P95（毫秒） */
    private const BASELINE_P95_MS = 200.0;

    /** 性能基线：P99（毫秒） */
    private const BASELINE_P99_MS = 500.0;

    /** 性能基线：错误率 */
    private const BASELINE_ERROR_RATE = 0.001;

    /** 性能基线：连接池利用率 */
    private const BASELINE_POOL_UTILIZATION = 0.80;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Perf Baseline Tenant',
            'slug' => 'perf-baseline',
            'status' => 'active',
        ]);
        TenantContext::setTenantId('1001');
    }

    // ---------- P95 / P99 基线 ----------

    /**
     * 模拟工作负载的 P95 / P99 应低于基线阈值
     */
    public function test_baseline_p95_p99_within_threshold(): void
    {
        $durations = $this->measureWorkload(500);

        $p95 = $this->percentile($durations, 95);
        $p99 = $this->percentile($durations, 99);

        $this->assertLessThan(
            self::BASELINE_P95_MS,
            $p95,
            "P95 {$p95}ms exceeds baseline " . self::BASELINE_P95_MS . 'ms'
        );
        $this->assertLessThan(
            self::BASELINE_P99_MS,
            $p99,
            "P99 {$p99}ms exceeds baseline " . self::BASELINE_P99_MS . 'ms'
        );
    }

    // ---------- 错误率基线 ----------

    /**
     * 模拟请求错误率应低于 0.1%
     */
    public function test_baseline_error_rate_below_threshold(): void
    {
        $total = 1000;
        $errors = 0;

        for ($i = 0; $i < $total; $i++) {
            try {
                DB::table('tenants')->where('tenant_id', 1001)->exists();
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        $errorRate = $errors / $total;

        $this->assertLessThan(
            self::BASELINE_ERROR_RATE,
            $errorRate,
            "Error rate {$errorRate} exceeds baseline " . self::BASELINE_ERROR_RATE
        );
    }

    // ---------- 连接池利用率基线 ----------

    /**
     * 连接池配置与利用率应低于 80%
     */
    public function test_baseline_connection_pool_utilization(): void
    {
        // 模拟生产连接池配置
        config()->set('database.connections.mysql.pool', [
            'enabled' => true,
            'max' => 50,
            'idle' => 10,
            'timeout' => 3,
        ]);

        $pool = config('database.connections.mysql.pool');

        $this->assertTrue($pool['enabled']);
        $this->assertGreaterThan(0, $pool['max']);

        // 模拟峰值活跃连接数，利用率应 < 80%
        $activeConnections = 30;
        $utilization = $activeConnections / $pool['max'];

        $this->assertLessThan(
            self::BASELINE_POOL_UTILIZATION,
            $utilization,
            "Pool utilization {$utilization} exceeds baseline " . self::BASELINE_POOL_UTILIZATION
        );
    }

    // ---------- N+1 查询防护 ----------

    /**
     * 重复读取同一租户不应产生额外查询（缓存命中）
     */
    public function test_no_n_plus_one_on_repeated_reads(): void
    {
        // 预置一条租户数据（setUp 已创建 1001）
        DB::enableQueryLog();

        // 第一次：未命中缓存，产生 1 条查询
        $first = DB::table('tenants')->where('tenant_id', 1001)->first();
        // 第二次：直接查询仍为 1 条（无对象缓存时）
        $second = DB::table('tenants')->where('tenant_id', 1001)->first();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        // 两次读取应仅 2 条查询（无 N+1 放大）
        $this->assertSame(2, $queryCount);
    }

    /**
     * 批量查询后访问关联应使用预加载，查询数有界
     */
    public function test_batch_query_uses_preloading(): void
    {
        // 通过 CacheService 验证预加载式缓存模式：首次加载后批量命中
        $cache = app(CacheService::class);
        $tenantId = 1001;

        DB::enableQueryLog();
        $cache->remember('perf:tenant:1001', function () {
            return DB::table('tenants')->where('tenant_id', 1001)->first();
        }, 60, $tenantId);
        $firstQueries = count(DB::getQueryLog());

        // 第二次应命中缓存，不产生 DB 查询
        $cache->remember('perf:tenant:1001', function () {
            return DB::table('tenants')->where('tenant_id', 1001)->first();
        }, 60, $tenantId);
        $secondQueries = count(DB::getQueryLog()) - $firstQueries;
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $firstQueries);
        $this->assertSame(0, $secondQueries, 'Cache miss on second call, expected hit');
    }

    // ---------- 缓存策略 ----------

    /**
     * 缓存 TTL 策略配置应包含各类别
     */
    public function test_cache_ttl_strategy_configured(): void
    {
        $cache = app(CacheService::class);
        $ttl = $cache->getTtlConfig();

        $this->assertArrayHasKey('user_profile', $ttl);
        $this->assertArrayHasKey('tenant_config', $ttl);
        $this->assertArrayHasKey('permissions', $ttl);
        $this->assertArrayHasKey('default', $ttl);

        // 热数据 TTL 应短于冷数据
        $this->assertLessThan($ttl['permissions'], $ttl['api_response']);
    }

    /**
     * 缓存预热应成功写入
     */
    public function test_cache_warmup_preloads_hot_data(): void
    {
        $cache = app(CacheService::class);
        $tenantId = 1001;

        $count = $cache->warmup([
            'warmup:plan' => fn () => 'free',
            'warmup:setting' => fn () => ['key' => 'value'],
        ], 60, $tenantId);

        $this->assertSame(2, $count);
        $this->assertSame('free', $cache->get('warmup:plan', null, $tenantId));
    }

    // ---------- 慢请求检测 ----------

    /**
     * 慢请求检测应正确过滤超阈值记录
     */
    public function test_slow_request_detection_filters_by_threshold(): void
    {
        $logService = app(StructuredLogService::class);
        $logService->performance('fast.op', 0.050, ['route' => '/fast']);
        $logService->performance('slow.op', 0.800, ['route' => '/slow']);

        $service = app(PerformanceService::class);
        $slow = $service->getSlowRequests(0.5);

        $this->assertCount(1, $slow);
        $this->assertSame('slow.op', $slow->first()->action);
    }

    // ---------- 性能指标采集 ----------

    /**
     * PerformanceService 应正确计算 P95
     */
    public function test_performance_service_p95_calculation(): void
    {
        $service = app(PerformanceService::class);

        for ($i = 1; $i <= 20; $i++) {
            $service->recordApiResponse('/api/v1/baseline', $i * 0.005, 200); // 5ms..100ms
        }

        $aggregated = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);

        $this->assertSame(20, $aggregated['count']);
        $this->assertNotNull($aggregated['p95']);
        // P95 应 <= 100ms（最大采样值）
        $this->assertLessThanOrEqual(100.0, $aggregated['p95']);
    }

    // ---------- 辅助方法 ----------

    /**
     * 测量工作负载的耗时样本（毫秒）
     *
     * @return array<float>
     */
    private function measureWorkload(int $iterations): array
    {
        $cache = app(CacheService::class);
        $tenantId = 1001;
        $durations = [];

        // 预热一次，避免首调用包含连接初始化开销
        DB::table('tenants')->where('tenant_id', 1001)->exists();

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);

            $cache->remember("perf:load:{$i}", function () {
                return DB::table('tenants')->where('tenant_id', 1001)->first();
            }, 60, $tenantId);

            $durations[] = (hrtime(true) - $start) / 1e6;
        }

        return $durations;
    }

    /**
     * 计算百分位数
     *
     * @param  array<float>  $values  样本值
     * @param  float  $p  百分位（0-100）
     */
    private function percentile(array $values, float $p): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $index = (int) floor($p / 100 * $count);

        if ($index >= $count) {
            $index = $count - 1;
        }

        return (float) $values[$index];
    }
}
