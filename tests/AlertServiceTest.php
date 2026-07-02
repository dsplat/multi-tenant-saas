<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\AlertService;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\EventModule;

/**
 * AlertService 单元测试
 *
 * 覆盖：告警触发、规则配置、规则列表（租户隔离）、告警历史查询、告警升级机制
 */
class AlertServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        TenantContext::setTenantId('1001');
    }

    // ---------- 告警触发 ----------

    public function test_trigger_records_alert_to_database(): void
    {
        $service = app(AlertService::class);

        $alertId = $service->trigger('cpu.high', AlertService::SEVERITY_WARNING, 'CPU usage exceeded 80%', ['cpu' => 85]);

        $this->assertGreaterThan(0, $alertId);

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNotNull($alert);
        $this->assertEquals(1001, $alert->tenant_id);
        $this->assertEquals('cpu.high', $alert->rule_name);
        $this->assertEquals('warning', $alert->severity);
        $this->assertStringContainsString('CPU usage', $alert->message);

        $context = json_decode($alert->context, true);
        $this->assertEquals(85, $context['cpu']);
    }

    public function test_trigger_records_tenant_id_from_context(): void
    {
        TenantContext::setTenantId('1002');

        $service = app(AlertService::class);

        $alertId = $service->trigger('memory.high', AlertService::SEVERITY_CRITICAL, 'Memory low');

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertEquals(1002, $alert->tenant_id);
    }

    // ---------- 规则配置 ----------

    public function test_configure_rule_creates_rule(): void
    {
        $service = app(AlertService::class);

        $ruleId = $service->configureRule([
            'name' => 'cpu.monitor',
            'metric' => 'cpu',
            'operator' => '>',
            'threshold' => 80,
            'severity' => AlertService::SEVERITY_WARNING,
            'channels' => [AlertService::CHANNEL_EMAIL],
            'cooldown_sec' => 300,
            'enabled' => true,
        ], 1001);

        $this->assertGreaterThan(0, $ruleId);

        $rule = DB::table('alert_rules')->where('id', $ruleId)->first();
        $this->assertNotNull($rule);
        $this->assertEquals(1001, $rule->tenant_id);
        $this->assertEquals('cpu.monitor', $rule->name);
        $this->assertEquals('warning', $rule->severity);
        $this->assertTrue((bool) $rule->enabled);
    }

    public function test_configure_rule_throws_when_name_empty(): void
    {
        $service = app(AlertService::class);

        $this->expectException(\RuntimeException::class);
        $service->configureRule(['name' => ''], 1001);
    }

    public function test_configure_rule_can_create_system_level_rule(): void
    {
        $service = app(AlertService::class);

        $ruleId = $service->configureRule([
            'name' => 'system.health',
            'metric' => 'health',
            'severity' => AlertService::SEVERITY_CRITICAL,
        ], null);

        $rule = DB::table('alert_rules')->where('id', $ruleId)->first();
        $this->assertNotNull($rule);
        $this->assertNull($rule->tenant_id);
    }

    public function test_toggle_rule_enables_and_disables(): void
    {
        $service = app(AlertService::class);

        $ruleId = $service->configureRule(['name' => 'toggle.test'], 1001);

        $service->toggleRule($ruleId, false);
        $rule = DB::table('alert_rules')->where('id', $ruleId)->first();
        $this->assertFalse((bool) $rule->enabled);

        $service->toggleRule($ruleId, true);
        $rule = DB::table('alert_rules')->where('id', $ruleId)->first();
        $this->assertTrue((bool) $rule->enabled);
    }

    // ---------- 规则列表（租户隔离）----------

    public function test_list_rules_returns_tenant_and_system_rules(): void
    {
        $service = app(AlertService::class);

        $service->configureRule(['name' => 'tenant-rule'], 1001);
        $service->configureRule(['name' => 'system-rule'], null);

        $rules = $service->listRules(1001);

        $this->assertCount(2, $rules);
        $names = $rules->pluck('name')->toArray();
        $this->assertContains('tenant-rule', $names);
        $this->assertContains('system-rule', $names);
    }

    public function test_list_rules_isolates_by_tenant(): void
    {
        $service = app(AlertService::class);

        $service->configureRule(['name' => 'rule-1001'], 1001);
        $service->configureRule(['name' => 'rule-1002'], 1002);
        $service->configureRule(['name' => 'system-rule'], null);

        $rules1001 = $service->listRules(1001);
        $this->assertCount(2, $rules1001);
        $this->assertContains('rule-1001', $rules1001->pluck('name')->toArray());
        $this->assertContains('system-rule', $rules1001->pluck('name')->toArray());
        $this->assertNotContains('rule-1002', $rules1001->pluck('name')->toArray());

        $rules1002 = $service->listRules(1002);
        $this->assertCount(2, $rules1002);
        $this->assertContains('rule-1002', $rules1002->pluck('name')->toArray());
    }

    public function test_list_rules_returns_only_system_when_no_tenant(): void
    {
        $service = app(AlertService::class);

        $service->configureRule(['name' => 'sys-only'], null);
        $service->configureRule(['name' => 'tenant-only'], 1001);

        $rules = $service->listRules(null);

        $this->assertCount(1, $rules);
        $this->assertEquals('sys-only', $rules->first()->name);
    }

    // ---------- 告警历史查询 ----------

    public function test_history_filters_by_tenant_context(): void
    {
        $service = app(AlertService::class);

        TenantContext::setTenantId('1001');
        $service->trigger('rule.a', AlertService::SEVERITY_WARNING, 'Alert A');

        TenantContext::setTenantId('1002');
        $service->trigger('rule.b', AlertService::SEVERITY_WARNING, 'Alert B');

        TenantContext::setTenantId('1001');
        $history = $service->history();
        $this->assertEquals(1, $history->total());
        $this->assertEquals('rule.a', $history->items()[0]->rule_name);
    }

    public function test_history_filters_by_severity(): void
    {
        $service = app(AlertService::class);

        $service->trigger('rule.info', AlertService::SEVERITY_INFO, 'Info');
        $service->trigger('rule.critical', AlertService::SEVERITY_CRITICAL, 'Critical');

        $history = $service->history(['severity' => 'critical']);
        $this->assertEquals(1, $history->total());
        $this->assertEquals('rule.critical', $history->items()[0]->rule_name);
    }

    public function test_history_filters_by_rule_name_prefix(): void
    {
        $service = app(AlertService::class);

        $service->trigger('cpu.high', AlertService::SEVERITY_WARNING, 'CPU');
        $service->trigger('memory.low', AlertService::SEVERITY_WARNING, 'Memory');

        $history = $service->history(['rule_name' => 'cpu']);
        $this->assertEquals(1, $history->total());
        $this->assertEquals('cpu.high', $history->items()[0]->rule_name);
    }

    // ---------- 告警升级机制 ----------

    public function test_should_escalate_returns_null_below_threshold(): void
    {
        $service = app(AlertService::class);

        $service->trigger('escalation.test', AlertService::SEVERITY_INFO, 'Alert 1');
        $service->trigger('escalation.test', AlertService::SEVERITY_INFO, 'Alert 2');

        $this->assertNull($service->shouldEscalate('escalation.test', 300));
    }

    public function test_should_escalate_returns_warning_at_three_triggers(): void
    {
        $service = app(AlertService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->trigger('escalation.warning', AlertService::SEVERITY_INFO, "Alert {$i}");
        }

        $this->assertEquals(AlertService::SEVERITY_WARNING, $service->shouldEscalate('escalation.warning', 300));
    }

    public function test_should_escalate_returns_critical_at_five_triggers(): void
    {
        $service = app(AlertService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->trigger('escalation.critical', AlertService::SEVERITY_WARNING, "Alert {$i}");
        }

        $this->assertEquals(AlertService::SEVERITY_CRITICAL, $service->shouldEscalate('escalation.critical', 300));
    }

    public function test_should_escalate_returns_fatal_at_ten_triggers(): void
    {
        $service = app(AlertService::class);

        for ($i = 0; $i < 10; $i++) {
            $service->trigger('escalation.fatal', AlertService::SEVERITY_CRITICAL, "Alert {$i}");
        }

        $this->assertEquals(AlertService::SEVERITY_FATAL, $service->shouldEscalate('escalation.fatal', 300));
    }
}
