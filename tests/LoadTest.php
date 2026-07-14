<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Jobs\ProcessWebhookDelivery;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Webhook;
use MultiTenantSaas\Modules\Infrastructure\Models\WebhookDelivery;
use MultiTenantSaas\Modules\Infrastructure\Services\CacheService;
use MultiTenantSaas\Modules\Infrastructure\Services\PerformanceService;
use MultiTenantSaas\Modules\Infrastructure\Services\WebhookService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * 负载测试
 *
 * 模拟多租户高并发场景，覆盖：
 *  - 1000+ 租户批量创建稳定性
 *  - 并发请求模拟与指标采集
 *  - 缓存命中率
 *  - 队列吞吐（外部 API 通过 Queue::fake mock，不真实调用）
 *  - N+1 查询防护
 *
 * 测试环境使用 SQLite 内存库 + array 缓存 + sync 队列，
 * 真实并发由循环模拟，重点验证机制正确性与系统稳定性。
 */
class LoadTest extends TestCase
{
    protected array $uses = [EventModule::class, PluginModule::class, WebhookModule::class];

    /** 负载测试租户规模 */
    private const TENANT_COUNT = 1000;

    /** 并发请求模拟次数（PerformanceService 单窗口采样上限 1000） */
    private const REQUEST_COUNT = 1000;

    /** 队列吞吐测试投递数 */
    private const QUEUE_DISPATCH_COUNT = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        // 建立一个基础租户供上下文使用
        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Load Base Tenant',
            'slug' => 'load-base',
            'status' => 'active',
        ]);
        TenantContext::setTenantId('1001');
    }

    // ---------- 批量租户创建 ----------

    /**
     * 1000+ 租户批量创建，系统保持稳定
     */
    public function test_bulk_tenant_creation_stability(): void
    {
        $start = hrtime(true);

        $count = self::TENANT_COUNT;
        $now = now()->toDateTimeString();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = 200000 + $i;
            $rows[] = [
                'tenant_id' => $id,
                'name' => "Load Tenant {$id}",
                'slug' => "load-tenant-{$id}",
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // 分批插入，每批 200 行，避免单次绑定参数过多
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('tenants')->insert($chunk);
        }

        $elapsedMs = (hrtime(true) - $start) / 1e6;

        $this->assertSame($count, (int) DB::table('tenants')->count() - 1);
        // 批量创建应在合理时间内完成（SQLite 内存库 5s 内）
        $this->assertLessThan(5000.0, $elapsedMs, "Bulk insert took {$elapsedMs}ms");
    }

    // ---------- 并发请求模拟 ----------

    /**
     * 模拟大量并发请求，验证指标采集与稳定性
     */
    public function test_concurrent_request_simulation_collects_metrics(): void
    {
        $tenants = $this->createTenants(50);
        $service = app(PerformanceService::class);

        // 指标按当前租户上下文聚合，固定基础租户以保证采样集中
        TenantContext::setTenantId('1001');

        $errors = 0;

        for ($i = 0; $i < self::REQUEST_COUNT; $i++) {
            $tenantId = $tenants[$i % count($tenants)];

            try {
                // 模拟一次跨租户 API 请求：DB 读取（不切换上下文以保证指标聚合）
                DB::table('tenants')->where('tenant_id', $tenantId)->exists();

                $service->recordApiResponse("/api/v1/load/{$i}", 0.012, 200);
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        $aggregated = $service->getAggregated(PerformanceService::METRIC_API_RESPONSE, 5);

        // PerformanceService 单窗口保留最近 1000 个采样
        $this->assertSame(self::REQUEST_COUNT, $aggregated['count']);
        // 错误率 < 0.1%
        $errorRate = $errors / self::REQUEST_COUNT;
        $this->assertLessThan(0.001, $errorRate, "Error rate too high: {$errorRate}");
    }

    // ---------- 缓存命中率 ----------

    /**
     * 热数据缓存命中率应达标
     */
    public function test_cache_hit_rate_under_load(): void
    {
        $cache = app(CacheService::class);
        $tenantId = 1001;
        TenantContext::setTenantId((string) $tenantId);

        $misses = 0;
        $total = 1000;

        for ($i = 0; $i < $total; $i++) {
            // 100 个不同 key，每个访问 10 次：首命中 + 9 次命中
            $key = 'hot:key:' . intdiv($i, 10);

            $cache->remember($key, function () use (&$misses) {
                $misses++;

                return 'value';
            }, 60, $tenantId);
        }

        $hits = $total - $misses;
        $hitRate = $hits / $total;

        // 10 次访问 / key，首命中后 9 次命中，命中率 90%
        $this->assertGreaterThanOrEqual(0.8, $hitRate, "Cache hit rate too low: {$hitRate}");
        $this->assertSame(100, $misses);
    }

    // ---------- 队列吞吐（mock 外部 API） ----------

    /**
     * 队列投递吞吐测试：外部 HTTP 调用通过 Queue::fake mock
     */
    public function test_queue_throughput_with_mocked_external_api(): void
    {
        Queue::fake();

        $start = hrtime(true);

        for ($i = 0; $i < self::QUEUE_DISPATCH_COUNT; $i++) {
            // 投递 Webhook 交付任务；Queue::fake 保证不真实调用外部 API
            ProcessWebhookDelivery::dispatch(200000 + $i);
        }

        $elapsedMs = (hrtime(true) - $start) / 1e6;

        Queue::assertPushed(ProcessWebhookDelivery::class, self::QUEUE_DISPATCH_COUNT);

        // 投递 1000 个任务应在 3s 内完成
        $this->assertLessThan(3000.0, $elapsedMs, "Dispatch took {$elapsedMs}ms");

        $throughputPerSec = self::QUEUE_DISPATCH_COUNT / max($elapsedMs / 1000, 0.001);
        $this->assertGreaterThan(100.0, $throughputPerSec, "Throughput too low: {$throughputPerSec}/s");
    }

    // ---------- N+1 查询防护 ----------

    /**
     * Webhook 交付列表预加载关联，访问关联不触发 N+1
     */
    public function test_no_n_plus_one_on_webhook_deliveries(): void
    {
        $tenantId = 1001;
        TenantContext::setTenantId((string) $tenantId);

        $webhook = Webhook::create([
            'url' => 'https://example.com/hook',
            'events' => ['tenant.created'],
            'secret' => str_repeat('a', 64),
            'is_active' => true,
        ]);

        // 创建 20 条交付记录
        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = [
                'webhook_delivery_id' => 300000 + $i,
                'webhook_id' => $webhook->webhook_id,
                'tenant_id' => $tenantId,
                'event_type' => 'tenant.created',
                'payload' => json_encode(['event' => 'tenant.created']),
                'attempts' => 0,
                'status' => WebhookDelivery::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('webhook_deliveries')->insert($rows);

        $service = app(WebhookService::class);

        DB::enableQueryLog();
        $deliveries = $service->getDeliveries($webhook->webhook_id);

        // 访问关联，不应产生额外查询（预加载生效）
        foreach ($deliveries as $delivery) {
            $_ = $delivery->webhook?->url;
        }
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 预加载后应为 2 条查询（交付列表 + 关联），容忍 <= 3
        $this->assertLessThanOrEqual(3, $queryCount, "N+1 detected: {$queryCount} queries");
        $this->assertCount(20, $deliveries);
    }

    // ---------- 辅助方法 ----------

    /**
     * 创建指定数量的租户并返回租户 ID 列表
     *
     * @return array<int>
     */
    private function createTenants(int $count): array
    {
        $now = now()->toDateTimeString();
        $rows = [];
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $id = 400000 + $i;
            $ids[] = $id;
            $rows[] = [
                'tenant_id' => $id,
                'name' => "Sim Tenant {$id}",
                'slug' => "sim-tenant-{$id}",
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('tenants')->insert($chunk);
        }

        return $ids;
    }
}
