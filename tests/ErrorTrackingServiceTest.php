<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\ErrorTrackingService;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * ErrorTrackingService 单元测试
 *
 * 覆盖：Sentry 集成开关、错误聚合、错误影响面分析、错误趋势图、错误通知、租户隔离
 */
class ErrorTrackingServiceTest extends TestCase
{
    protected array $uses = [PluginModule::class];

    protected ?ErrorTrackingService $service = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');

        Tenant::create(['tenant_id' => 1001, 'name' => 'Error Tenant', 'slug' => 'error-tenant', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        TenantContext::setTenantId('1001');

        $this->service = app(ErrorTrackingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 插入一条错误日志
     */
    protected function insertError(
        int $tenantId,
        ?int $userId,
        string $action,
        string $message,
        string $createdAt,
    ): void {
        DB::table('structured_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'category' => 'error',
            'action' => $action,
            'context' => json_encode(['message' => $message], JSON_UNESCAPED_UNICODE),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => $createdAt,
        ]);
    }

    // ---------- Sentry 集成 ----------

    public function test_capture_exception_returns_null_when_sentry_disabled(): void
    {
        $this->assertNull($this->service->captureException(new \RuntimeException('boom')));
    }

    public function test_capture_message_returns_null_when_sentry_disabled(): void
    {
        $this->assertNull($this->service->captureMessage('hello'));
    }

    public function test_capture_exception_returns_null_when_enabled_but_sdk_absent(): void
    {
        config()->set('tenancy.error_tracking.sentry.enabled', true);

        // Sentry SDK 在测试环境未安装，应安全降级返回 null
        $this->assertNull($this->service->captureException(new \RuntimeException('boom')));
    }

    // ---------- 错误聚合 ----------

    public function test_aggregate_errors_groups_by_action(): void
    {
        $this->insertError(1001, 5001, 'user.create.failed', 'DB error', '2026-06-15 10:00:00');
        $this->insertError(1001, 5002, 'user.create.failed', 'DB error', '2026-06-15 10:05:00');
        $this->insertError(1001, 5003, 'payment.charge.failed', 'Gateway timeout', '2026-06-15 11:00:00');

        $groups = $this->service->aggregateErrors('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertCount(2, $groups);

        // 按次数倒序，user.create.failed 出现 2 次应排首位
        $this->assertEquals('user.create.failed', $groups[0]['action']);
        $this->assertEquals(2, $groups[0]['count']);
        $this->assertEquals(2, $groups[0]['affected_users']);
        $this->assertEquals(1, $groups[0]['affected_tenants']);
        $this->assertEquals('DB error', $groups[0]['message']);
        $this->assertNotEmpty($groups[0]['fingerprint']);

        $this->assertEquals('payment.charge.failed', $groups[1]['action']);
        $this->assertEquals(1, $groups[1]['count']);
    }

    public function test_aggregate_errors_respects_time_window(): void
    {
        $this->insertError(1001, null, 'job.failed', 'err', '2026-06-14 10:00:00');
        $this->insertError(1001, null, 'job.failed', 'err', '2026-06-15 10:00:00');

        $groups = $this->service->aggregateErrors('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertCount(1, $groups);
        $this->assertEquals(1, $groups[0]['count']);
    }

    public function test_aggregate_errors_isolated_by_tenant(): void
    {
        $this->insertError(1001, null, 'tenant.a.error', 'err', '2026-06-15 10:00:00');
        $this->insertError(1002, null, 'tenant.b.error', 'err', '2026-06-15 10:00:00');

        // 当前上下文为 1001
        $groups = $this->service->aggregateErrors('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertCount(1, $groups);
        $this->assertEquals('tenant.a.error', $groups[0]['action']);
    }

    public function test_aggregate_errors_cross_tenant_when_no_context(): void
    {
        $this->insertError(1001, null, 'shared.error', 'err', '2026-06-15 10:00:00');
        $this->insertError(1002, null, 'shared.error', 'err', '2026-06-15 10:05:00');

        // 清除租户上下文，模拟 admin 域名下的跨租户聚合
        TenantContext::clear();

        $groups = $this->service->aggregateErrors('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertCount(1, $groups);
        $this->assertEquals(2, $groups[0]['count']);
        $this->assertEquals(2, $groups[0]['affected_tenants']);
    }

    public function test_aggregate_errors_tracks_first_and_last_seen(): void
    {
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 08:00:00');
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 20:00:00');

        $groups = $this->service->aggregateErrors('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertCount(1, $groups);
        $this->assertEquals('2026-06-15 08:00:00', $groups[0]['first_seen']);
        $this->assertEquals('2026-06-15 20:00:00', $groups[0]['last_seen']);
    }

    public function test_aggregate_errors_returns_empty_when_no_data(): void
    {
        $groups = $this->service->aggregateErrors('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertIsArray($groups);
        $this->assertEmpty($groups);
    }

    // ---------- 错误影响面分析 ----------

    public function test_analyze_impact_counts_tenants_and_users(): void
    {
        $this->insertError(1001, 5001, 'a.failed', 'err', '2026-06-15 10:00:00');
        $this->insertError(1001, 5002, 'b.failed', 'err', '2026-06-15 10:05:00');
        $this->insertError(1002, 5003, 'c.failed', 'err', '2026-06-15 11:00:00');

        // 清除租户上下文，模拟 admin 域名下的跨租户影响面分析
        TenantContext::clear();

        $impact = $this->service->analyzeImpact('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertEquals(3, $impact['total_errors']);
        $this->assertEquals(2, $impact['affected_tenants']);
        $this->assertEquals(3, $impact['affected_users']);
        $this->assertCount(2, $impact['top_tenants']);
        // top_tenants 按次数倒序，1001 出现 2 次应排首位
        $this->assertEquals(1001, $impact['top_tenants'][0]['tenant_id']);
        $this->assertEquals(2, $impact['top_tenants'][0]['count']);
    }

    public function test_analyze_impact_by_action(): void
    {
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 10:00:00');
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 10:05:00');
        $this->insertError(1001, null, 'y.failed', 'err', '2026-06-15 11:00:00');

        $impact = $this->service->analyzeImpact('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertCount(2, $impact['by_action']);
        $this->assertEquals('x.failed', $impact['by_action'][0]['action']);
        $this->assertEquals(2, $impact['by_action'][0]['count']);
    }

    // ---------- 错误趋势图 ----------

    public function test_error_trend_by_day(): void
    {
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-14 10:00:00');
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 10:00:00');
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 11:00:00');

        $trend = $this->service->errorTrend('2026-06-14 00:00:00', '2026-06-15 23:59:59');

        $this->assertCount(2, $trend);
        $this->assertEquals('2026-06-14', $trend[0]['bucket']);
        $this->assertEquals(1, $trend[0]['count']);
        $this->assertEquals('2026-06-15', $trend[1]['bucket']);
        $this->assertEquals(2, $trend[1]['count']);
    }

    public function test_error_trend_by_hour(): void
    {
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 10:00:00');
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 10:30:00');
        $this->insertError(1001, null, 'x.failed', 'err', '2026-06-15 11:00:00');

        $trend = $this->service->errorTrend('2026-06-15 00:00:00', '2026-06-15 23:59:59', ErrorTrackingService::GRANULARITY_HOUR);

        $this->assertCount(2, $trend);
        $this->assertEquals('2026-06-15 10:00:00', $trend[0]['bucket']);
        $this->assertEquals(2, $trend[0]['count']);
        $this->assertEquals('2026-06-15 11:00:00', $trend[1]['bucket']);
        $this->assertEquals(1, $trend[1]['count']);
    }

    public function test_error_trend_empty_returns_empty_array(): void
    {
        $trend = $this->service->errorTrend('2026-06-15 00:00:00', '2026-06-15 23:59:59');

        $this->assertIsArray($trend);
        $this->assertEmpty($trend);
    }

    // ---------- 错误通知 ----------

    public function test_notify_error_creates_alert_record(): void
    {
        $alertId = $this->service->notifyError(
            'error.spike',
            ErrorTrackingService::SEVERITY_CRITICAL,
            'Error rate spiked',
            ['action' => 'user.create.failed', 'count' => 10],
        );

        $this->assertGreaterThan(0, $alertId);

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNotNull($alert);
        $this->assertEquals('error.spike', $alert->rule_name);
        $this->assertEquals(ErrorTrackingService::SEVERITY_CRITICAL, $alert->severity);
        $this->assertEquals(1001, (int) $alert->tenant_id);
    }
}
