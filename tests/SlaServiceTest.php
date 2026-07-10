<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\SlaEvent;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\SlaService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * SlaService 单元测试
 *
 * 覆盖：事件记录、事件解决、可用性计算、SLA 达标率、违约检测、活跃事件查询、历史查询
 */
class SlaServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        // 冻结时间到月中，便于按月统计
        Carbon::setTestNow('2026-06-15 12:00:00');

        // 显式加载 health.sla 配置（Testbench 不会自动加载 config/health.php）
        config([
            'health.sla' => [
                'enabled' => true,
                'default_level' => 'standard',
                'levels' => [
                    'standard' => 99.9,
                    'premium' => 99.95,
                    'enterprise' => 99.99,
                ],
                'check_period' => 'monthly',
                'alert_min_severity' => 'critical',
            ],
        ]);

        Tenant::create(['tenant_id' => 1001, 'name' => 'Sla Tenant', 'slug' => 'sla-tenant', 'status' => 'active']);

        TenantContext::setTenantId('1001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- 事件记录 ----------

    public function test_record_event_inserts_row(): void
    {
        $service = app(SlaService::class);

        $eventId = $service->recordEvent(
            eventType: SlaEvent::EVENT_DOWNTIME,
            severity: SlaEvent::SEVERITY_CRITICAL,
            startedAt: '2026-06-15 10:00:00',
            endedAt: '2026-06-15 10:05:00',
            affectedScope: 'tenant:1001',
            affectedCount: 1
        );

        $this->assertGreaterThan(0, $eventId);

        $row = DB::table('sla_events')->where('sla_event_id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertEquals('downtime', $row->event_type);
        $this->assertEquals('critical', $row->severity);
        $this->assertEquals('tenant:1001', $row->affected_scope);
        $this->assertEquals(1, $row->affected_count);
        $this->assertEquals('resolved', $row->status);
        $this->assertEquals(300, $row->duration_sec); // 5 min = 300 sec
        $this->assertEquals(1001, $row->tenant_id);
    }

    public function test_record_event_without_end_is_active(): void
    {
        $service = app(SlaService::class);

        $eventId = $service->recordEvent(
            eventType: SlaEvent::EVENT_DEGRADATION,
            severity: SlaEvent::SEVERITY_WARNING,
            startedAt: '2026-06-15 10:00:00'
        );

        $row = DB::table('sla_events')->where('sla_event_id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertEquals('active', $row->status);
        $this->assertNull($row->ended_at);
        $this->assertEquals(0, $row->duration_sec);
    }

    public function test_record_event_uses_tenant_context(): void
    {
        $service = app(SlaService::class);

        $eventId = $service->recordEvent(
            eventType: SlaEvent::EVENT_MAINTENANCE,
            severity: SlaEvent::SEVERITY_INFO,
            startedAt: '2026-06-15 10:00:00',
            endedAt: '2026-06-15 10:30:00'
        );

        $row = DB::table('sla_events')->where('sla_event_id', $eventId)->first();
        $this->assertEquals(1001, $row->tenant_id);
    }

    public function test_record_downtime_convenience_method(): void
    {
        $service = app(SlaService::class);

        $eventId = $service->recordDowntime(
            startedAt: '2026-06-15 10:00:00',
            endedAt: '2026-06-15 10:10:00'
        );

        $row = DB::table('sla_events')->where('sla_event_id', $eventId)->first();
        $this->assertEquals('downtime', $row->event_type);
        $this->assertEquals('critical', $row->severity);
        $this->assertEquals(600, $row->duration_sec);
    }

    public function test_record_degradation_convenience_method(): void
    {
        $service = app(SlaService::class);

        $eventId = $service->recordDegradation(
            startedAt: '2026-06-15 10:00:00',
            endedAt: '2026-06-15 10:05:00'
        );

        $row = DB::table('sla_events')->where('sla_event_id', $eventId)->first();
        $this->assertEquals('degradation', $row->event_type);
        $this->assertEquals('warning', $row->severity);
    }

    public function test_record_downtime_triggers_alert(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime(
            startedAt: '2026-06-15 10:00:00',
            endedAt: '2026-06-15 10:01:00'
        );

        $alert = DB::table('alerts')->where('rule_name', 'sla.downtime')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert->severity);
    }

    // ---------- 事件解决 ----------

    public function test_resolve_event_closes_active_event(): void
    {
        $service = app(SlaService::class);

        $eventId = $service->recordDowntime(
            startedAt: '2026-06-15 10:00:00'
        );

        $affected = $service->resolveEvent(
            eventId: $eventId,
            endedAt: '2026-06-15 10:15:00',
            resolutionNotes: 'restarted service'
        );

        $this->assertEquals(1, $affected);

        $row = DB::table('sla_events')->where('sla_event_id', $eventId)->first();
        $this->assertEquals('resolved', $row->status);
        $this->assertEquals(900, $row->duration_sec); // 15 min
        $this->assertEquals('restarted service', $row->resolution_notes);
    }

    public function test_resolve_event_nonexistent_returns_zero(): void
    {
        $service = app(SlaService::class);

        $affected = $service->resolveEvent(99999999);

        $this->assertEquals(0, $affected);
    }

    // ---------- 可用性计算 ----------

    public function test_calculate_availability_full_when_no_events(): void
    {
        $service = app(SlaService::class);

        $avail = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00'
        );

        $this->assertEquals(100.0, $avail);
    }

    public function test_calculate_availability_with_downtime_inside_window(): void
    {
        $service = app(SlaService::class);

        // 窗口 600 秒
        $service->recordDowntime(
            startedAt: '2026-06-15 12:01:00',
            endedAt: '2026-06-15 12:03:00' // 120 sec
        );

        $avail = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00'
        );

        // (600-120)/600 * 100 = 80
        $this->assertEquals(80.0, $avail);
    }

    public function test_calculate_availability_with_downtime_starting_before_window(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime(
            startedAt: '2026-06-15 11:55:00',
            endedAt: '2026-06-15 12:02:00' // 裁剪后 12:00-12:02 = 120 sec
        );

        $avail = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00'
        );

        $this->assertEquals(80.0, $avail);
    }

    public function test_calculate_availability_with_downtime_extending_beyond_window(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime(
            startedAt: '2026-06-15 12:08:00',
            endedAt: '2026-06-15 12:15:00' // 裁剪后 12:08-12:10 = 120 sec
        );

        $avail = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00'
        );

        $this->assertEquals(80.0, $avail);
    }

    public function test_calculate_availability_with_ongoing_downtime(): void
    {
        $service = app(SlaService::class);

        // 未结束的停机，应裁剪到窗口末尾
        $service->recordDowntime(
            startedAt: '2026-06-15 12:05:00' // 12:05 到 12:10 = 300 sec
        );

        $avail = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00'
        );

        // (600-300)/600 * 100 = 50
        $this->assertEquals(50.0, $avail);
    }

    public function test_calculate_availability_only_counts_downtime(): void
    {
        $service = app(SlaService::class);

        // degradation 不计入可用性分母
        $service->recordDegradation(
            startedAt: '2026-06-15 12:01:00',
            endedAt: '2026-06-15 12:09:00' // 480 sec
        );

        $avail = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00'
        );

        $this->assertEquals(100.0, $avail);
    }

    public function test_calculate_availability_isolated_by_tenant(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime(
            startedAt: '2026-06-15 12:01:00',
            endedAt: '2026-06-15 12:03:00',
            tenantId: 1001
        );

        // 租户 1001：80%
        $avail1 = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00',
            1001
        );
        $this->assertEquals(80.0, $avail1);

        // 租户 1002：100%（含系统级 NULL 事件，但无系统级 downtime）
        $avail2 = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00',
            1002
        );
        $this->assertEquals(100.0, $avail2);
    }

    public function test_calculate_availability_includes_system_level_events(): void
    {
        $service = app(SlaService::class);

        // 系统级停机（tenant_id=null）应影响所有租户的可用性
        $service->recordDowntime(
            startedAt: '2026-06-15 12:01:00',
            endedAt: '2026-06-15 12:03:00',
            tenantId: null
        );

        $avail = $service->calculateAvailability(
            '2026-06-15 12:00:00',
            '2026-06-15 12:10:00',
            1001
        );

        $this->assertEquals(80.0, $avail);
    }

    // ---------- SLA 达标率 ----------

    public function test_get_sla_compliance_returns_structure(): void
    {
        $service = app(SlaService::class);

        $result = $service->getSlaCompliance(SlaService::PERIOD_MONTHLY);

        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('level', $result);
        $this->assertArrayHasKey('target', $result);
        $this->assertArrayHasKey('actual', $result);
        $this->assertArrayHasKey('compliant', $result);
        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('to', $result);

        $this->assertEquals('monthly', $result['period']);
        $this->assertEquals('standard', $result['level']);
        $this->assertEquals(99.9, $result['target']);
    }

    public function test_get_sla_compliance_compliant_without_downtime(): void
    {
        $service = app(SlaService::class);

        $result = $service->getSlaCompliance(SlaService::PERIOD_MONTHLY);

        $this->assertEquals(100.0, $result['actual']);
        $this->assertTrue($result['compliant']);
    }

    public function test_get_sla_compliance_non_compliant_with_large_downtime(): void
    {
        $service = app(SlaService::class);

        // 1 小时停机，足以跌破 99.9%（月允许约 43.2 分钟）
        $service->recordDowntime(
            startedAt: '2026-06-10 10:00:00',
            endedAt: '2026-06-10 11:00:00' // 3600 sec
        );

        $result = $service->getSlaCompliance(SlaService::PERIOD_MONTHLY, SlaService::LEVEL_STANDARD);

        $this->assertLessThan(99.9, $result['actual']);
        $this->assertFalse($result['compliant']);
    }

    public function test_get_sla_compliance_enterprise_level_stricter(): void
    {
        $service = app(SlaService::class);

        // 5 分钟停机
        $service->recordDowntime(
            startedAt: '2026-06-10 10:00:00',
            endedAt: '2026-06-10 10:05:00' // 300 sec
        );

        $standard = $service->getSlaCompliance(SlaService::PERIOD_MONTHLY, SlaService::LEVEL_STANDARD);
        $enterprise = $service->getSlaCompliance(SlaService::PERIOD_MONTHLY, SlaService::LEVEL_ENTERPRISE);

        // 5 分钟停机在月度上 > 99.9 但 < 99.99
        $this->assertTrue($standard['compliant']); // 99.9 容忍 43 分钟
        $this->assertFalse($enterprise['compliant']); // 99.99 仅容忍 4.3 分钟
    }

    public function test_get_sla_compliance_quarterly_period(): void
    {
        $service = app(SlaService::class);

        $result = $service->getSlaCompliance(SlaService::PERIOD_QUARTERLY);

        $this->assertEquals('quarterly', $result['period']);
        $this->assertTrue($result['compliant']);
    }

    public function test_get_sla_compliance_yearly_period(): void
    {
        $service = app(SlaService::class);

        $result = $service->getSlaCompliance(SlaService::PERIOD_YEARLY);

        $this->assertEquals('yearly', $result['period']);
        $this->assertTrue($result['compliant']);
    }

    // ---------- 违约检测 ----------

    public function test_check_sla_breaches_returns_empty_when_compliant(): void
    {
        $service = app(SlaService::class);

        $breaches = $service->checkSlaBreaches();

        $this->assertEquals([], $breaches);
    }

    public function test_check_sla_breaches_detects_all_levels_when_large_downtime(): void
    {
        $service = app(SlaService::class);

        // 2 小时停机，足以跌破所有等级
        $service->recordDowntime(
            startedAt: '2026-06-10 10:00:00',
            endedAt: '2026-06-10 12:00:00'
        );

        $breaches = $service->checkSlaBreaches();

        $this->assertCount(3, $breaches);

        $levels = array_column($breaches, 'level');
        $this->assertContains('standard', $levels);
        $this->assertContains('premium', $levels);
        $this->assertContains('enterprise', $levels);
    }

    public function test_check_sla_breaches_triggers_alert(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime(
            startedAt: '2026-06-10 10:00:00',
            endedAt: '2026-06-10 12:00:00'
        );

        $service->checkSlaBreaches();

        $breachAlert = DB::table('alerts')->where('rule_name', 'sla.breach')->first();
        $this->assertNotNull($breachAlert);
        $this->assertEquals('critical', $breachAlert->severity);
    }

    // ---------- 活跃事件 ----------

    public function test_get_active_events_returns_only_active(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime('2026-06-15 10:00:00', '2026-06-15 10:05:00'); // resolved
        $service->recordDowntime('2026-06-15 11:00:00'); // active

        $active = $service->getActiveEvents();

        $this->assertEquals(1, $active->count());
        $this->assertEquals('active', $active->first()->status);
    }

    public function test_get_active_events_filter_by_tenant(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime('2026-06-15 11:00:00', null, 'tenant:1001', 1, 1001);

        // 系统级事件
        $service->recordDowntime('2026-06-15 11:00:00', null, 'global', 0, null);

        $activeFor1001 = $service->getActiveEvents(1001);
        // 应同时返回租户级与系统级
        $this->assertGreaterThanOrEqual(1, $activeFor1001->count());
    }

    // ---------- 历史查询 ----------

    public function test_history_returns_paginated_results(): void
    {
        $service = app(SlaService::class);

        for ($i = 0; $i < 15; $i++) {
            $service->recordDowntime(
                startedAt: '2026-06-' . sprintf('%02d', 10 + $i) . ' 10:00:00',
                endedAt: '2026-06-' . sprintf('%02d', 10 + $i) . ' 10:01:00'
            );
        }

        $paginator = $service->history([], 10);

        $this->assertEquals(15, $paginator->total());
        $this->assertEquals(10, $paginator->count());
    }

    public function test_history_filters_by_event_type(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime('2026-06-15 10:00:00', '2026-06-15 10:01:00');
        $service->recordDegradation('2026-06-15 11:00:00', '2026-06-15 11:01:00');

        $downtimes = $service->history(['event_type' => 'downtime']);

        $this->assertEquals(1, $downtimes->total());
    }

    public function test_history_filters_by_status(): void
    {
        $service = app(SlaService::class);

        $service->recordDowntime('2026-06-15 10:00:00', '2026-06-15 10:01:00'); // resolved
        $service->recordDowntime('2026-06-15 11:00:00'); // active

        $active = $service->history(['status' => 'active']);

        $this->assertEquals(1, $active->total());
    }
}
