<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\ResourceService;

/**
 * ResourceService 单元测试
 *
 * 覆盖：数据库连接数、队列积压、缓存命中率、存储用量、
 * 租户资源占用比例、资源告警阈值检查
 */
class ResourceServiceTest extends TestCase
{
    protected ?ResourceService $service = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');

        Tenant::create(['tenant_id' => 1001, 'name' => 'Resource Tenant', 'slug' => 'res-tenant', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        TenantContext::setTenantId('1001');

        $this->service = app(ResourceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- 数据库连接数 ----------

    public function test_get_db_connections_returns_int(): void
    {
        $conns = $this->service->getDbConnections();

        $this->assertIsInt($conns);
        $this->assertGreaterThanOrEqual(0, $conns);
    }

    // ---------- 队列积压量 ----------

    public function test_get_queue_backlog_returns_structure(): void
    {
        $backlog = $this->service->getQueueBacklog();

        $this->assertIsArray($backlog);
        $this->assertArrayHasKey('queues', $backlog);
        $this->assertArrayHasKey('total_pending', $backlog);
        $this->assertArrayHasKey('total_failed', $backlog);
        $this->assertArrayHasKey('horizon', $backlog);
        $this->assertIsInt($backlog['total_pending']);
    }

    public function test_get_queue_backlog_returns_zero_without_horizon(): void
    {
        $backlog = $this->service->getQueueBacklog();

        // 测试环境无 Horizon
        $this->assertFalse($backlog['horizon']);
        $this->assertEquals(0, $backlog['total_pending']);
    }

    // ---------- 缓存命中率 ----------

    public function test_get_cache_hit_rate_returns_structure(): void
    {
        $stats = $this->service->getCacheHitRate();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('driver', $stats);
        $this->assertArrayHasKey('tenant_keys', $stats);
        $this->assertArrayHasKey('hit_rate', $stats);

        // array 驱动无命中率
        $this->assertNull($stats['hit_rate']);
    }

    // ---------- 存储用量 ----------

    public function test_get_storage_usage_returns_zero_without_files(): void
    {
        $storage = $this->service->getStorageUsage();

        $this->assertEquals(0, $storage['total_bytes']);
        $this->assertEquals(0.0, $storage['total_mb']);
        $this->assertEquals(0, $storage['file_count']);
    }

    public function test_get_storage_usage_aggregates_files(): void
    {
        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        DB::table('file_uploads')->insert([
            [
                'file_upload_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'user_id' => null,
                'disk' => 'local',
                'path' => '/a.txt',
                'filename' => 'a.txt',
                'size' => 1048576, // 1 MB
                'category' => 'general',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'file_upload_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'user_id' => null,
                'disk' => 'local',
                'path' => '/b.txt',
                'filename' => 'b.txt',
                'size' => 2097152, // 2 MB
                'category' => 'general',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $storage = $this->service->getStorageUsage();

        $this->assertEquals(3145728, $storage['total_bytes']); // 3 MB
        $this->assertEquals(3.0, $storage['total_mb']);
        $this->assertEquals(2, $storage['file_count']);
    }

    public function test_get_storage_usage_isolates_by_tenant(): void
    {
        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        DB::table('file_uploads')->insert([
            'file_upload_id' => $idGen->generate(),
            'tenant_id' => 1001,
            'user_id' => null,
            'disk' => 'local',
            'path' => '/a.txt',
            'filename' => 'a.txt',
            'size' => 1048576,
            'category' => 'general',
            'is_public' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 1002 的存储不应出现在 1001 的统计中
        $storage = $this->service->getStorageUsage(1002);

        $this->assertEquals(0, $storage['total_bytes']);
    }

    // ---------- 租户资源占用比例 ----------

    public function test_get_tenant_resource_ratios_returns_empty_without_files(): void
    {
        $ratios = $this->service->getTenantResourceRatios();

        $this->assertEquals([], $ratios);
    }

    public function test_get_tenant_resource_ratios_calculates_proportions(): void
    {
        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        // 1001: 1MB, 1002: 3MB -> 总 4MB
        DB::table('file_uploads')->insert([
            [
                'file_upload_id' => $idGen->generate(),
                'tenant_id' => 1001,
                'user_id' => null,
                'disk' => 'local',
                'path' => '/a.txt',
                'filename' => 'a.txt',
                'size' => 1048576,
                'category' => 'general',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'file_upload_id' => $idGen->generate(),
                'tenant_id' => 1002,
                'user_id' => null,
                'disk' => 'local',
                'path' => '/b.txt',
                'filename' => 'b.txt',
                'size' => 3145728,
                'category' => 'general',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $ratios = $this->service->getTenantResourceRatios();

        $this->assertCount(2, $ratios);

        // 按占比降序，1002 应排第一
        $this->assertEquals(1002, $ratios[0]['tenant_id']);
        $this->assertEquals(0.75, $ratios[0]['ratio']);
        $this->assertEquals(1001, $ratios[1]['tenant_id']);
        $this->assertEquals(0.25, $ratios[1]['ratio']);
    }

    // ---------- 资源告警阈值检查 ----------

    public function test_check_alert_thresholds_returns_empty_when_within_limits(): void
    {
        // 默认阈值较高，无告警
        $alerts = $this->service->checkAlertThresholds();

        $this->assertIsArray($alerts);
        // 缓存命中率在 array 驱动下为 null（不触发），DB 连接数为 1（远低于 100）
        $this->assertEmpty($alerts);
    }

    public function test_check_alert_thresholds_triggers_storage_alert(): void
    {
        Config::set('tenancy.resource_monitoring.storage_usage_threshold_mb', 0);

        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        DB::table('file_uploads')->insert([
            'file_upload_id' => $idGen->generate(),
            'tenant_id' => 1001,
            'user_id' => null,
            'disk' => 'local',
            'path' => '/a.txt',
            'filename' => 'a.txt',
            'size' => 1048576,
            'category' => 'general',
            'is_public' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $alerts = $this->service->checkAlertThresholds();

        $this->assertCount(1, $alerts);
        $this->assertEquals('storage_usage', $alerts[0]['metric']);
        $this->assertNotEmpty($alerts[0]['message']);
    }

    public function test_check_alert_thresholds_triggers_db_connections_alert(): void
    {
        Config::set('tenancy.resource_monitoring.db_connections_threshold', 0);

        $alerts = $this->service->checkAlertThresholds();

        $dbAlerts = array_filter($alerts, fn ($a) => $a['metric'] === 'db_connections');
        $this->assertCount(1, $dbAlerts);
    }

    public function test_check_alert_thresholds_triggers_multiple_alerts(): void
    {
        Config::set('tenancy.resource_monitoring.db_connections_threshold', 0);
        Config::set('tenancy.resource_monitoring.storage_usage_threshold_mb', 0);

        $idGen = app(IdGeneratorContract::class);
        $now = now()->toDateTimeString();

        DB::table('file_uploads')->insert([
            'file_upload_id' => $idGen->generate(),
            'tenant_id' => 1001,
            'user_id' => null,
            'disk' => 'local',
            'path' => '/a.txt',
            'filename' => 'a.txt',
            'size' => 1048576,
            'category' => 'general',
            'is_public' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $alerts = $this->service->checkAlertThresholds();

        $metrics = array_column($alerts, 'metric');
        $this->assertContains('db_connections', $metrics);
        $this->assertContains('storage_usage', $metrics);
    }
}
