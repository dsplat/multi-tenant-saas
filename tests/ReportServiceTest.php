<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\CustomReport;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\ReportService;

/**
 * ReportService 单元测试
 *
 * 覆盖：报表 CRUD、数据生成、CSV 导出、PDF/Excel 降级、定时调度、
 * 定时发送、报表模板、租户隔离
 */
class ReportServiceTest extends TestCase
{
    protected ?ReportService $service = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');

        Tenant::create(['tenant_id' => 1001, 'name' => 'Report Tenant', 'slug' => 'report-tenant', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        TenantContext::setTenantId('1001');

        $this->service = app(ReportService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 插入一条错误日志
     */
    protected function insertError(int $tenantId, string $action, string $createdAt): void
    {
        DB::table('structured_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => null,
            'category' => 'error',
            'action' => $action,
            'context' => json_encode(['message' => 'err'], JSON_UNESCAPED_UNICODE),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => $createdAt,
        ]);
    }

    /**
     * 插入一条成本分摊记录
     */
    protected function insertCost(int $tenantId, string $period, float $amount): void
    {
        $idGen = app(IdGeneratorContract::class);

        DB::table('cost_allocations')->insert([
            'cost_allocation_id' => $idGen->generate(),
            'tenant_id' => $tenantId,
            'cost_type' => 'infrastructure',
            'cost_subtype' => 'compute',
            'amount' => $amount,
            'currency' => 'CNY',
            'period' => $period,
            'allocation_basis' => 'by_users',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    // ---------- 报表 CRUD ----------

    public function test_create_report_creates_with_defaults(): void
    {
        $report = $this->service->createReport('Daily Errors', ['metrics' => ['errors']]);

        $this->assertNotNull($report->custom_report_id);
        $this->assertEquals('Daily Errors', $report->name);
        $this->assertEquals(1001, (int) $report->tenant_id);
        $this->assertEquals(['metrics' => ['errors']], $report->metrics_config);
        $this->assertEquals(CustomReport::RANGE_LAST_7_DAYS, $report->time_range);
        $this->assertEquals(CustomReport::FREQUENCY_DAILY, $report->frequency);
        $this->assertEquals(ReportService::FORMAT_CSV, $report->format);
        $this->assertEquals(CustomReport::STATUS_ACTIVE, $report->status);
    }

    public function test_create_report_with_custom_options(): void
    {
        $report = $this->service->createReport(
            'Weekly Costs',
            ['metrics' => ['costs'], 'aggregation' => 'sum'],
            [
                'dimensions' => ['by_day'],
                'time_range' => CustomReport::RANGE_LAST_30_DAYS,
                'frequency' => CustomReport::FREQUENCY_WEEKLY,
                'recipients' => ['ops@example.com'],
                'format' => ReportService::FORMAT_EXCEL,
                'description' => 'Weekly cost overview',
            ],
        );

        $this->assertEquals(CustomReport::FREQUENCY_WEEKLY, $report->frequency);
        $this->assertEquals(ReportService::FORMAT_EXCEL, $report->format);
        $this->assertEquals(['by_day'], $report->dimensions);
        $this->assertEquals(['ops@example.com'], $report->recipients);
        $this->assertEquals(CustomReport::RANGE_LAST_30_DAYS, $report->time_range);
    }

    public function test_update_report_modifies_fields(): void
    {
        $report = $this->service->createReport('Report A', ['metrics' => ['errors']]);

        $updated = $this->service->updateReport($report->custom_report_id, [
            'name' => 'Report A Updated',
            'frequency' => CustomReport::FREQUENCY_MONTHLY,
            'status' => CustomReport::STATUS_PAUSED,
        ]);

        $this->assertEquals('Report A Updated', $updated->name);
        $this->assertEquals(CustomReport::FREQUENCY_MONTHLY, $updated->frequency);
        $this->assertEquals(CustomReport::STATUS_PAUSED, $updated->status);
    }

    public function test_update_report_returns_null_when_not_found(): void
    {
        $this->assertNull($this->service->updateReport(99999, ['name' => 'X']));
    }

    public function test_delete_report_soft_deletes(): void
    {
        $report = $this->service->createReport('To Delete', ['metrics' => ['errors']]);

        $this->assertTrue($this->service->deleteReport($report->custom_report_id));
        $this->assertNull(CustomReport::find($report->custom_report_id));
        // 软删除记录仍存在
        $this->assertNotNull(CustomReport::withTrashed()->find($report->custom_report_id));
    }

    public function test_delete_report_returns_false_when_not_found(): void
    {
        $this->assertFalse($this->service->deleteReport(99999));
    }

    public function test_get_report_returns_instance(): void
    {
        $report = $this->service->createReport('Find Me', ['metrics' => ['errors']]);

        $found = $this->service->getReport($report->custom_report_id);

        $this->assertNotNull($found);
        $this->assertEquals('Find Me', $found->name);
    }

    public function test_list_reports_isolated_by_tenant(): void
    {
        $this->service->createReport('R1', ['metrics' => ['errors']]);
        $this->service->createReport('R2', ['metrics' => ['errors']]);

        TenantContext::setTenantId('1002');
        $this->service->createReport('R3', ['metrics' => ['errors']]);

        TenantContext::setTenantId('1001');
        $list = $this->service->listReports();
        $this->assertEquals(2, $list->total());

        TenantContext::setTenantId('1002');
        $list = $this->service->listReports();
        $this->assertEquals(1, $list->total());
    }

    // ---------- 数据生成 ----------

    public function test_generate_data_aggregates_errors_metric(): void
    {
        $this->insertError(1001, 'x.failed', '2026-06-15 10:00:00');
        $this->insertError(1001, 'x.failed', '2026-06-15 11:00:00');

        $report = $this->service->createReport(
            'Errors',
            ['metrics' => ['errors']],
            ['dimensions' => ['by_day'], 'time_range' => CustomReport::RANGE_LAST_7_DAYS],
        );

        $data = $this->service->generateData($report);

        $this->assertEquals('Errors', $data['report']['name']);
        $this->assertArrayHasKey('errors', $data['metrics']);
        $this->assertEquals(2, $data['metrics']['errors']['total']);
        $this->assertArrayHasKey('2026-06-15', $data['metrics']['errors']['by_day']);
        $this->assertEquals(2, $data['metrics']['errors']['by_day']['2026-06-15']);
        $this->assertEquals(2, $data['summary']['errors']);
    }

    public function test_generate_data_aggregates_costs_metric(): void
    {
        $this->insertCost(1001, '2026-06', 1000.0);
        $this->insertCost(1001, '2026-06', 500.0);

        $report = $this->service->createReport(
            'Costs',
            ['metrics' => ['costs']],
            ['dimensions' => ['by_day'], 'time_range' => CustomReport::RANGE_LAST_7_DAYS],
        );

        $data = $this->service->generateData($report);

        $this->assertEquals(1500.0, $data['metrics']['costs']['total']);
        $this->assertArrayHasKey('2026-06', $data['metrics']['costs']['by_day']);
    }

    public function test_generate_data_with_by_tenant_dimension(): void
    {
        $this->insertError(1001, 'x.failed', '2026-06-15 10:00:00');
        $this->insertError(1001, 'x.failed', '2026-06-15 11:00:00');

        $report = $this->service->createReport(
            'Errors by tenant',
            ['metrics' => ['errors']],
            ['dimensions' => ['by_tenant'], 'time_range' => CustomReport::RANGE_LAST_7_DAYS],
        );

        $data = $this->service->generateData($report);

        $this->assertArrayHasKey(1001, $data['metrics']['errors']['by_tenant']);
        $this->assertEquals(2, $data['metrics']['errors']['by_tenant'][1001]);
    }

    public function test_generate_data_respects_custom_time_range(): void
    {
        $this->insertError(1001, 'old.failed', '2026-05-15 10:00:00');
        $this->insertError(1001, 'new.failed', '2026-06-15 10:00:00');

        $report = $this->service->createReport(
            'Custom range',
            ['metrics' => ['errors']],
            [
                'time_range' => CustomReport::RANGE_CUSTOM,
                'start_at' => '2026-06-01 00:00:00',
                'end_at' => '2026-06-30 23:59:59',
            ],
        );

        $data = $this->service->generateData($report);

        $this->assertEquals(1, $data['metrics']['errors']['total']);
    }

    public function test_generate_data_empty_metrics_returns_empty(): void
    {
        $report = $this->service->createReport('Empty', ['metrics' => []]);

        $data = $this->service->generateData($report);

        $this->assertEmpty($data['metrics']);
        $this->assertEmpty($data['summary']);
    }

    // ---------- 导出 ----------

    public function test_export_csv_produces_content(): void
    {
        $this->insertError(1001, 'x.failed', '2026-06-15 10:00:00');
        $this->insertError(1001, 'x.failed', '2026-06-15 11:00:00');

        $report = $this->service->createReport(
            'CSV Report',
            ['metrics' => ['errors']],
            ['dimensions' => ['by_day'], 'time_range' => CustomReport::RANGE_LAST_7_DAYS],
        );

        $csv = $this->service->export($report, ReportService::FORMAT_CSV);

        $this->assertStringContainsString('section', $csv);
        $this->assertStringContainsString('key', $csv);
        $this->assertStringContainsString('value', $csv);
        $this->assertStringContainsString('summary', $csv);
        $this->assertStringContainsString('errors', $csv);
        $this->assertStringContainsString('2026-06-15', $csv);
    }

    public function test_export_csv_uses_report_default_format(): void
    {
        $report = $this->service->createReport('Default Format', ['metrics' => ['errors']]);

        $csv = $this->service->export($report);

        $this->assertStringContainsString('section', $csv);
    }

    public function test_export_pdf_throws_when_library_unavailable(): void
    {
        $report = $this->service->createReport('PDF Report', ['metrics' => ['errors']]);

        $this->expectException(\RuntimeException::class);
        $this->service->export($report, ReportService::FORMAT_PDF);
    }

    public function test_export_excel_throws_when_library_unavailable(): void
    {
        $report = $this->service->createReport('Excel Report', ['metrics' => ['errors']]);

        $this->expectException(\RuntimeException::class);
        $this->service->export($report, ReportService::FORMAT_EXCEL);
    }

    public function test_export_invalid_format_throws(): void
    {
        $report = $this->service->createReport('Bad Format', ['metrics' => ['errors']]);

        $this->expectException(\RuntimeException::class);
        $this->service->export($report, 'unknown');
    }

    // ---------- 定时调度 ----------

    public function test_schedule_returns_cron_for_daily(): void
    {
        $report = $this->service->createReport('Daily', ['metrics' => ['errors']], ['frequency' => CustomReport::FREQUENCY_DAILY]);

        $this->assertEquals('0 8 * * *', $this->service->schedule($report));
    }

    public function test_schedule_returns_cron_for_weekly(): void
    {
        $report = $this->service->createReport('Weekly', ['metrics' => ['errors']], ['frequency' => CustomReport::FREQUENCY_WEEKLY]);

        $this->assertEquals('0 8 * * 1', $this->service->schedule($report));
    }

    public function test_schedule_returns_cron_for_monthly(): void
    {
        $report = $this->service->createReport('Monthly', ['metrics' => ['errors']], ['frequency' => CustomReport::FREQUENCY_MONTHLY]);

        $this->assertEquals('0 8 1 * *', $this->service->schedule($report));
    }

    public function test_schedule_throws_for_invalid_frequency(): void
    {
        $report = $this->service->createReport('Bad', ['metrics' => ['errors']]);
        $report->forceFill(['frequency' => 'hourly'])->save();

        $this->expectException(\RuntimeException::class);
        $this->service->schedule($report);
    }

    // ---------- 定时发送 ----------

    public function test_send_report_delivers_to_recipients(): void
    {
        $this->insertError(1001, 'x.failed', '2026-06-15 10:00:00');

        $report = $this->service->createReport(
            'Send Report',
            ['metrics' => ['errors']],
            [
                'recipients' => ['a@example.com', 'b@example.com'],
                'format' => ReportService::FORMAT_CSV,
            ],
        );

        $sent = $this->service->sendReport($report);

        $this->assertEquals(2, $sent);

        $updated = $this->service->getReport($report->custom_report_id);
        $this->assertNotNull($updated->last_sent_at);
        $this->assertNotNull($updated->next_send_at);
    }

    public function test_send_report_returns_zero_without_recipients(): void
    {
        $report = $this->service->createReport('No Recipients', ['metrics' => ['errors']]);

        $this->assertEquals(0, $this->service->sendReport($report));
    }

    public function test_send_report_falls_back_to_csv_when_format_unavailable(): void
    {
        $this->insertError(1001, 'x.failed', '2026-06-15 10:00:00');

        $report = $this->service->createReport(
            'PDF Send',
            ['metrics' => ['errors']],
            [
                'recipients' => ['a@example.com'],
                'format' => ReportService::FORMAT_PDF,
            ],
        );

        // PDF 库未安装，应回退到 CSV 并成功投递
        $sent = $this->service->sendReport($report);

        $this->assertEquals(1, $sent);
    }

    // ---------- 报表模板 ----------

    public function test_apply_template_returns_config(): void
    {
        $config = $this->service->applyTemplate('errors_summary');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('metrics_config', $config);
        $this->assertArrayHasKey('dimensions', $config);
        $this->assertEquals('csv', $config['format']);
    }

    public function test_apply_template_throws_for_unknown(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->applyTemplate('does_not_exist');
    }
}
