<?php

namespace MultiTenantSaas\Modules\Monitoring\Services;

use Barryvdh\DomPDF\Facade\Pdf;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Infrastructure\Services\ExcelService;
use MultiTenantSaas\Modules\Infrastructure\Services\PdfService;
use MultiTenantSaas\Modules\Monitoring\Models\CustomReport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * 租户自定义报表服务
 *
 * 提供租户级自定义报表能力：
 *  - 报表 CRUD（选择指标 + 维度 + 时间范围 + 发送频率 + 接收人）
 *  - 报表数据生成（按指标聚合，支持按天 / 按租户维度）
 *  - 定时发送（日报 / 周报 / 月报，基于 Laravel Scheduler）
 *  - 报表模板（预置指标组合）
 *  - 导出格式（CSV 原生 / PDF 复用 PdfService / Excel 复用 ExcelService）
 *
 * 租户隔离：所有报表按 tenant_id 过滤；CustomReport 使用 BelongsToTenant。
 *
 * 依赖：TenantContextContract。导出时按需调用 PdfService / ExcelService，
 * 当对应扩展库（barryvdh/laravel-dompdf、maatwebsite/excel）未安装时优雅降级抛出异常。
 */
class ReportService
{
    /** 导出格式：CSV */
    public const FORMAT_CSV = 'csv';

    /** 导出格式：Excel */
    public const FORMAT_EXCEL = 'excel';

    /** 导出格式：PDF */
    public const FORMAT_PDF = 'pdf';

    /** 频率：日报 */
    public const FREQUENCY_DAILY = CustomReport::FREQUENCY_DAILY;

    /** 频率：周报 */
    public const FREQUENCY_WEEKLY = CustomReport::FREQUENCY_WEEKLY;

    /** 频率：月报 */
    public const FREQUENCY_MONTHLY = CustomReport::FREQUENCY_MONTHLY;

    /** 支持的指标：错误数 */
    public const METRIC_ERRORS = 'errors';

    /** 支持的指标：告警数 */
    public const METRIC_ALERTS = 'alerts';

    /** 支持的指标：AI 请求数 */
    public const METRIC_AI_REQUESTS = 'ai_requests';

    /** 支持的指标：成本金额 */
    public const METRIC_COSTS = 'costs';

    /** 维度：按天 */
    public const DIMENSION_BY_DAY = 'by_day';

    /** 维度：按租户 */
    public const DIMENSION_BY_TENANT = 'by_tenant';

    public function __construct(
        protected TenantContextContract $tenantContext,
    ) {}

    /**
     * 创建自定义报表
     *
     * @param  string  $name  报表名称
     * @param  array<string, mixed>  $metricsConfig  指标配置（如 {metrics:["errors"],aggregation:"count"}）
     * @param  array{
     *     description?: string,
     *     dimensions?: array<string>,
     *     time_range?: string,
     *     start_at?: string|null,
     *     end_at?: string|null,
     *     frequency?: string,
     *     recipients?: array<string>,
     *     format?: string,
     *     template?: string|null,
     *     status?: string
     * }  $options  其他配置
     */
    public function createReport(string $name, array $metricsConfig, array $options = []): CustomReport
    {
        return CustomReport::create([
            'name' => $name,
            'description' => $options['description'] ?? null,
            'metrics_config' => $metricsConfig,
            'dimensions' => $options['dimensions'] ?? [],
            'time_range' => $options['time_range'] ?? CustomReport::RANGE_LAST_7_DAYS,
            'start_at' => $options['start_at'] ?? null,
            'end_at' => $options['end_at'] ?? null,
            'frequency' => $options['frequency'] ?? CustomReport::FREQUENCY_DAILY,
            'recipients' => $options['recipients'] ?? [],
            'format' => $options['format'] ?? self::FORMAT_CSV,
            'template' => $options['template'] ?? null,
            'status' => $options['status'] ?? CustomReport::STATUS_ACTIVE,
            'next_send_at' => $options['next_send_at'] ?? null,
        ]);
    }

    /**
     * 更新报表配置
     *
     * @param  int  $reportId  报表 ID
     * @param  array<string, mixed>  $data  可更新字段
     */
    public function updateReport(int $reportId, array $data): ?CustomReport
    {
        $report = CustomReport::find($reportId);
        if ($report === null) {
            return null;
        }

        $fillable = (new CustomReport)->getFillable();
        $update = array_intersect_key($data, array_flip($fillable));
        if (! empty($update)) {
            $report->fill($update)->save();
        }

        return $report->fresh();
    }

    /**
     * 删除报表（软删除）
     */
    public function deleteReport(int $reportId): bool
    {
        $report = CustomReport::find($reportId);

        return $report !== null ? $report->delete() : false;
    }

    /**
     * 获取单个报表
     */
    public function getReport(int $reportId): ?CustomReport
    {
        return CustomReport::find($reportId);
    }

    /**
     * 分页列出当前租户的报表
     *
     * @param  int  $perPage  每页条数
     */
    public function listReports(int $perPage = 15): LengthAwarePaginator
    {
        return CustomReport::query()->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * 生成报表数据
     *
     * 依据 metrics_config 中的 metrics 列表与 dimensions 维度聚合数据。
     *
     * @return array{
     *     report: array{name: string, period: string, generated_at: string},
     *     metrics: array<string, array{total: float|int, by_day: array<string,float|int>, by_tenant: array<int|string,float|int>}>,
     *     summary: array<string, float|int>
     * }
     */
    public function generateData(CustomReport $report): array
    {
        [$from, $to] = $this->resolveTimeRange($report);

        $config = (array) $report->metrics_config;
        $metrics = $config['metrics'] ?? [];
        $metrics = is_array($metrics) ? $metrics : [];
        $dimensions = is_array($report->dimensions) ? $report->dimensions : [];

        $result = [
            'report' => [
                'name' => $report->name,
                'period' => "{$from} ~ {$to}",
                'generated_at' => now()->toDateTimeString(),
            ],
            'metrics' => [],
            'summary' => [],
        ];

        foreach ($metrics as $metric) {
            $result['metrics'][$metric] = $this->aggregateMetric((string) $metric, $from, $to, $dimensions);
        }

        // 汇总每个指标的总数
        foreach ($result['metrics'] as $metric => $data) {
            $result['summary'][$metric] = $data['total'];
        }

        return $result;
    }

    /**
     * 导出报表为指定格式
     *
     * @param  CustomReport  $report  报表实例
     * @param  string|null  $format  格式（csv / excel / pdf），默认取报表自身 format
     * @return string 文件内容
     *
     * @throws \RuntimeException 当请求的导出库未安装时
     */
    public function export(CustomReport $report, ?string $format = null): string
    {
        $format = $format ?? $report->format ?? self::FORMAT_CSV;
        $data = $this->generateData($report);
        $rows = $this->flattenForExport($data);

        return match ($format) {
            self::FORMAT_CSV => $this->exportCsv($rows),
            self::FORMAT_EXCEL => $this->exportExcel($rows),
            self::FORMAT_PDF => $this->exportPdf($data),
            default => throw new \RuntimeException(trans('common.report_format_invalid')),
        };
    }

    /**
     * 计算定时发送的 cron 表达式
     *
     * @return string cron 表达式
     *
     * @throws \RuntimeException 不支持的频率
     */
    public function schedule(CustomReport $report): string
    {
        return match ($report->frequency) {
            self::FREQUENCY_DAILY => '0 8 * * *',          // 每日 08:00
            self::FREQUENCY_WEEKLY => '0 8 * * 1',         // 每周一 08:00
            self::FREQUENCY_MONTHLY => '0 8 1 * *',        // 每月 1 日 08:00
            default => throw new \RuntimeException(trans('common.report_frequency_invalid')),
        };
    }

    /**
     * 发送报表给接收人
     *
     * 生成数据并导出为报表配置的格式，通过日志通道投递（生产环境可接入邮件）。
     *
     * @param  CustomReport  $report  报表实例
     * @param  string|null  $format  覆盖格式
     * @return int 已投递的接收人数
     */
    public function sendReport(CustomReport $report, ?string $format = null): int
    {
        $recipients = is_array($report->recipients) ? $report->recipients : [];
        if (empty($recipients)) {
            return 0;
        }

        $format = $format ?? $report->format ?? self::FORMAT_CSV;

        try {
            $content = $this->export($report, $format);
        } catch (Throwable $e) {
            Log::warning('[ReportService] export failed, fallback to csv', [
                'report_id' => $report->custom_report_id,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);
            $content = $this->export($report, self::FORMAT_CSV);
            $format = self::FORMAT_CSV;
        }

        foreach ($recipients as $email) {
            // 生产环境接入邮件通道；此处通过日志记录以保证测试可断言
            Log::info('[ReportService] send report', [
                'report_id' => $report->custom_report_id,
                'recipient' => $email,
                'format' => $format,
            ]);
        }

        $report->update([
            'last_sent_at' => now(),
            'next_send_at' => $this->computeNextSendAt($report->frequency),
        ]);

        return count($recipients);
    }

    /**
     * 应用预置报表模板
     *
     * @param  string  $templateName  模板名
     * @return array<string, mixed> 模板配置（metrics_config + dimensions + format）
     *
     * @throws \RuntimeException 模板不存在
     */
    public function applyTemplate(string $templateName): array
    {
        $templates = (array) config('tenancy.reports.templates', []);
        if (! isset($templates[$templateName])) {
            throw new \RuntimeException(trans('common.report_template_not_found'));
        }

        return (array) $templates[$templateName];
    }

    // ---------- 内部辅助：数据聚合 ----------

    /**
     * 聚合单个指标
     *
     * @param  string  $metric  指标名
     * @param  string  $from  起始时间
     * @param  string  $to  截止时间
     * @param  array<int, string>  $dimensions  维度列表
     * @return array{total: float|int, by_day: array<string,float|int>, by_tenant: array<int|string,float|int>}
     */
    protected function aggregateMetric(string $metric, string $from, string $to, array $dimensions): array
    {
        $byDay = [];
        $byTenant = [];
        $total = 0;

        switch ($metric) {
            case self::METRIC_ERRORS:
                $total = $this->countErrors($from, $to);
                if (in_array(self::DIMENSION_BY_DAY, $dimensions, true)) {
                    $byDay = $this->countErrorsByDay($from, $to);
                }
                if (in_array(self::DIMENSION_BY_TENANT, $dimensions, true)) {
                    $byTenant = $this->countErrorsByTenant($from, $to);
                }
                break;

            case self::METRIC_ALERTS:
                $total = $this->countAlerts($from, $to);
                if (in_array(self::DIMENSION_BY_DAY, $dimensions, true)) {
                    $byDay = $this->countAlertsByDay($from, $to);
                }
                break;

            case self::METRIC_AI_REQUESTS:
                $total = $this->countAiRequests($from, $to);
                if (in_array(self::DIMENSION_BY_DAY, $dimensions, true)) {
                    $byDay = $this->countAiRequestsByDay($from, $to);
                }
                break;

            case self::METRIC_COSTS:
                $total = $this->sumCosts($from, $to);
                if (in_array(self::DIMENSION_BY_DAY, $dimensions, true)) {
                    $byDay = $this->sumCostsByDay($from, $to);
                }
                break;

            default:
                $total = 0;
                break;
        }

        return [
            'total' => $total,
            'by_day' => $byDay,
            'by_tenant' => $byTenant,
        ];
    }

    /**
     * 解析报表时间范围
     *
     * @return array{0: string, 1: string} [起始, 截止] Y-m-d H:i:s
     */
    protected function resolveTimeRange(CustomReport $report): array
    {
        if ($report->time_range === CustomReport::RANGE_CUSTOM
            && $report->start_at !== null
            && $report->end_at !== null) {
            return [
                $report->start_at->startOfDay()->toDateTimeString(),
                $report->end_at->endOfDay()->toDateTimeString(),
            ];
        }

        $end = now()->endOfDay();

        return match ($report->time_range) {
            CustomReport::RANGE_LAST_30_DAYS => [now()->subDays(29)->startOfDay()->toDateTimeString(), $end->toDateTimeString()],
            CustomReport::RANGE_LAST_MONTH => [now()->subMonth()->startOfMonth()->toDateTimeString(), now()->subMonth()->endOfMonth()->toDateTimeString()],
            default => [now()->subDays(6)->startOfDay()->toDateTimeString(), $end->toDateTimeString()],
        };
    }

    protected function resolveTenantId(): ?int
    {
        $contextId = $this->tenantContext->resolveId();

        return $contextId !== null ? (int) $contextId : null;
    }

    // ---------- 指标查询 ----------

    protected function countErrors(string $from, string $to): int
    {
        if (! Schema::hasTable('structured_logs')) {
            return 0;
        }

        $query = DB::table('structured_logs')
            ->where('category', 'error')
            ->whereBetween('created_at', [$from, $to]);

        $tid = $this->resolveTenantId();
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        return (int) $query->count();
    }

    /**
     * @return array<string, int>
     */
    protected function countErrorsByDay(string $from, string $to): array
    {
        if (! Schema::hasTable('structured_logs')) {
            return [];
        }

        $rows = $this->bucketByDay('structured_logs', 'created_at', $from, $to, function ($q) {
            $q->where('category', 'error');
            $tid = $this->resolveTenantId();
            if ($tid !== null) {
                $q->where('tenant_id', $tid);
            }
        });

        $out = [];
        foreach ($rows as $r) {
            $out[$r->bucket] = (int) $r->count;
        }

        return $out;
    }

    /**
     * @return array<int|string, int>
     */
    protected function countErrorsByTenant(string $from, string $to): array
    {
        if (! Schema::hasTable('structured_logs')) {
            return [];
        }

        $query = DB::table('structured_logs')
            ->select('tenant_id', DB::raw('COUNT(*) as count'))
            ->where('category', 'error')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('tenant_id')
            ->groupBy('tenant_id');

        $tid = $this->resolveTenantId();
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        $out = [];
        foreach ($query->get() as $r) {
            $out[(int) $r->tenant_id] = (int) $r->count;
        }

        return $out;
    }

    protected function countAlerts(string $from, string $to): int
    {
        if (! Schema::hasTable('alerts')) {
            return 0;
        }

        $query = DB::table('alerts')->whereBetween('triggered_at', [$from, $to]);
        $tid = $this->resolveTenantId();
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        return (int) $query->count();
    }

    /**
     * @return array<string, int>
     */
    protected function countAlertsByDay(string $from, string $to): array
    {
        if (! Schema::hasTable('alerts')) {
            return [];
        }

        $rows = $this->bucketByDay('alerts', 'triggered_at', $from, $to, function ($q) {
            $tid = $this->resolveTenantId();
            if ($tid !== null) {
                $q->where('tenant_id', $tid);
            }
        });

        $out = [];
        foreach ($rows as $r) {
            $out[$r->bucket] = (int) $r->count;
        }

        return $out;
    }

    protected function countAiRequests(string $from, string $to): int
    {
        if (! Schema::hasTable('ai_requests')) {
            return 0;
        }

        $query = DB::table('ai_requests')->whereBetween('created_at', [$from, $to]);
        $tid = $this->resolveTenantId();
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        return (int) $query->count();
    }

    /**
     * @return array<string, int>
     */
    protected function countAiRequestsByDay(string $from, string $to): array
    {
        if (! Schema::hasTable('ai_requests')) {
            return [];
        }

        $rows = $this->bucketByDay('ai_requests', 'created_at', $from, $to, function ($q) {
            $tid = $this->resolveTenantId();
            if ($tid !== null) {
                $q->where('tenant_id', $tid);
            }
        });

        $out = [];
        foreach ($rows as $r) {
            $out[$r->bucket] = (int) $r->count;
        }

        return $out;
    }

    protected function sumCosts(string $from, string $to): float
    {
        if (! Schema::hasTable('cost_allocations')) {
            return 0.0;
        }

        // cost_allocations 按 period（YYYY-MM）聚合，故将时间范围转为月份范围
        $query = DB::table('cost_allocations');
        $this->applyPeriodRange($query, $from, $to);

        $tid = $this->resolveTenantId();
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        return (float) $query->sum('amount');
    }

    /**
     * @return array<string, float>
     */
    protected function sumCostsByDay(string $from, string $to): array
    {
        // cost_allocations 按月聚合，按天维度退化为按月汇总
        if (! Schema::hasTable('cost_allocations')) {
            return [];
        }

        $query = DB::table('cost_allocations')
            ->select('period', DB::raw('SUM(amount) as total'))
            ->groupBy('period');
        $this->applyPeriodRange($query, $from, $to);

        $tid = $this->resolveTenantId();
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        $out = [];
        foreach ($query->get() as $r) {
            $out[$r->period] = round((float) $r->total, 4);
        }

        return $out;
    }

    /**
     * 为 cost_allocations 查询附加月份范围条件
     */
    protected function applyPeriodRange($query, string $from, string $to): void
    {
        $fromPeriod = substr($from, 0, 7);
        $toPeriod = substr($to, 0, 7);
        $query->whereBetween('period', [$fromPeriod, $toPeriod]);
    }

    /**
     * 通用按天分桶聚合
     *
     * @param  string  $table  表名
     * @param  string  $column  时间列
     * @param  string  $from  起始
     * @param  string  $to  截止
     * @param  callable($query): void  $scope  额外查询条件
     * @return Collection
     */
    protected function bucketByDay(string $table, string $column, string $from, string $to, callable $scope)
    {
        // 使用 SUBSTR 取日期部分，兼容 SQLite 与 MySQL
        $query = DB::table($table)
            ->selectRaw("SUBSTR({$column}, 1, 10) as bucket, COUNT(*) as count")
            ->whereBetween($column, [$from, $to])
            ->groupBy('bucket');

        $scope($query);

        return $query->orderBy('bucket')->get();
    }

    // ---------- 内部辅助：导出 ----------

    /**
     * 将生成数据扁平化为导出行
     *
     * @return array<int, array{section: string, key: string, value: string}>
     */
    protected function flattenForExport(array $data): array
    {
        $rows = [];

        // 汇总区
        foreach (($data['summary'] ?? []) as $metric => $value) {
            $rows[] = ['section' => 'summary', 'key' => (string) $metric, 'value' => (string) $value];
        }

        // 指标明细区
        foreach (($data['metrics'] ?? []) as $metric => $info) {
            foreach (($info['by_day'] ?? []) as $bucket => $value) {
                $rows[] = ['section' => (string) $metric, 'key' => (string) $bucket, 'value' => (string) $value];
            }
            foreach (($info['by_tenant'] ?? []) as $tenant => $value) {
                $rows[] = ['section' => (string) $metric, 'key' => 'tenant:' . $tenant, 'value' => (string) $value];
            }
        }

        return $rows;
    }

    /**
     * 导出 CSV（原生 PHP，UTF-8 BOM）
     *
     * @param  array<int, array{section: string, key: string, value: string}>  $rows
     */
    protected function exportCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        fputcsv($handle, ['section', 'key', 'value']);
        foreach ($rows as $row) {
            fputcsv($handle, [$row['section'], $row['key'], $row['value']]);
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * 导出 Excel（基于 phpoffice/phpspreadsheet 2.0）
     *
     * @param  array<int, array{section: string, key: string, value: string}>  $rows
     *
     * @throws \RuntimeException 当导出失败
     */
    protected function exportExcel(array $rows): string
    {
        $headings = ['section', 'key', 'value'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // 写入表头
        $col = 1;
        foreach ($headings as $heading) {
            $sheet->setCellValue([$col, 1], $heading);
            $col++;
        }

        // 写入数据行
        $row = 2;
        foreach ($rows as $item) {
            $col = 1;
            foreach (array_values($item) as $value) {
                $sheet->setCellValue([$col, $row], $value);
                $col++;
            }
            $row++;
        }

        // 写入临时文件并读取内容
        $tempFile = tempnam(sys_get_temp_dir(), 'report_');
        try {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tempFile);
            $content = (string) file_get_contents($tempFile);
        } catch (Throwable $e) {
            throw new \RuntimeException(trans('common.report_export_unavailable'), 0, $e);
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return $content;
    }

    /**
     * 导出 PDF（依赖 barryvdh/laravel-dompdf）
     *
     * @param  array<string, mixed>  $data  报表数据
     *
     * @throws \RuntimeException 当 dompdf 未安装
     */
    protected function exportPdf(array $data): string
    {
        if (! class_exists(Pdf::class)) {
            throw new \RuntimeException(trans('common.report_export_unavailable'));
        }

        $view = (string) config('tenancy.reports.pdf_view', 'pdf.report');

        try {
            return (string) PdfService::generate($view, $data);
        } catch (Throwable $e) {
            throw new \RuntimeException(trans('common.report_export_unavailable'), 0, $e);
        }
    }

    /**
     * 计算下次发送时间
     */
    protected function computeNextSendAt(string $frequency): ?string
    {
        return match ($frequency) {
            self::FREQUENCY_DAILY => now()->addDay()->setTime(8, 0)->toDateTimeString(),
            self::FREQUENCY_WEEKLY => now()->addWeek()->setTime(8, 0)->toDateTimeString(),
            self::FREQUENCY_MONTHLY => now()->addMonth()->setTime(8, 0)->toDateTimeString(),
            default => null,
        };
    }
}
