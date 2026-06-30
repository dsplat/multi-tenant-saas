<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\RetentionService;

/**
 * 数据保留处理命令
 *
 * 每天运行，检查过期数据并按照保留策略清理。
 * 支持清理前通知（记录即将过期的数据）。
 *
 * 用法：
 *   php artisan gdpr:process-retention
 *   php artisan gdpr:process-retention --dry-run
 */
class ProcessDataRetention extends Command
{
    protected $signature = 'gdpr:process-retention
                            {--dry-run : 仅检查不实际清理}';

    protected $description = '处理数据保留策略，清理过期数据（GDPR 合规）';

    public function handle(RetentionService $service): int
    {
        $this->info(trans('tenant.retention_cleanup_starting'));

        $dryRun = (bool) $this->option('dry-run');

        // Dry-run 模式：仅统计过期数据
        if ($dryRun) {
            $expired = $service->findExpiredData();

            $this->info(trans('tenant.retention_dry_run_mode'));
            $this->line(trans('tenant.retention_expired_total', ['count' => $expired['total']]));

            if ($expired['total'] > 0) {
                $this->table(
                    [trans('tenant.retention_data_type'), trans('tenant.retention_record_count')],
                    array_map(
                        fn ($type, $count) => [$type, $count],
                        array_keys($expired['details']),
                        $expired['details']
                    )
                );
            }

            return self::SUCCESS;
        }

        // 清理前通知
        $noticeDays = (int) config('tenancy.gdpr.cleanup_notice_days', 7);
        $expiring = $service->notifyBeforeCleanup($noticeDays);

        if (! empty($expiring)) {
            $this->warn(trans('tenant.retention_data_expiring_soon'));
            foreach ($expiring as $type => $count) {
                $this->line("  {$type}: {$count}");
            }
        }

        // 清理过期数据
        $cleaned = $service->cleanupExpiredData();

        $this->info(trans('tenant.retention_cleanup_completed'));
        $this->line(trans('tenant.retention_records_cleaned', ['count' => $cleaned]));

        return self::SUCCESS;
    }
}
