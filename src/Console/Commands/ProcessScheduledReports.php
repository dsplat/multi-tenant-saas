<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\CustomReport;
use MultiTenantSaas\Services\ReportService;

/**
 * 发送定时报表
 *
 * 查询 next_send_at <= now 且 status=active 的报表并发送。
 */
class ProcessScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled
        {--dry-run : 只显示待发送报表，不实际发送}';

    protected $description = '发送定时报表（按 CustomReport.frequency 和 next_send_at）';

    public function handle(ReportService $reportService): int
    {
        if (! class_exists(CustomReport::class)) {
            $this->info('CustomReport 模型不存在，跳过');

            return self::SUCCESS;
        }

        $dueReports = CustomReport::query()
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now())
            ->where('status', 'active')
            ->get();

        if ($dueReports->isEmpty()) {
            $this->info('没有待发送的报表');

            return self::SUCCESS;
        }

        $this->info("找到 {$dueReports->count()} 个待发送报表");

        if ($this->option('dry-run')) {
            foreach ($dueReports as $report) {
                $this->line("  [DRY] Report #{$report->id}: {$report->name} → next_send_at={$report->next_send_at}");
            }

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($dueReports as $report) {
            try {
                $reportService->sendReport($report);
                $sent++;
            } catch (\Throwable $e) {
                Log::error('[ProcessScheduledReports] Report failed', [
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->info("发送完成: {$sent} 成功, {$failed} 失败");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
