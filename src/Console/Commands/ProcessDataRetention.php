<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\RetentionService;

class ProcessDataRetention extends Command
{
    protected $signature = 'data:retention {--dry-run : 预览模式，不实际执行清理}';
    protected $description = '执行数据保留策略清理（删除/匿名化过期数据）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $service = app(RetentionService::class);

        if ($dryRun) {
            $this->info('Dry-run mode: 预览过期数据，不执行清理');
            $result = $service->findExpiredData();
            foreach ($result['details'] ?? [] as $dataType => $count) {
                $this->line("  [{$dataType}] {$count} 条过期记录");
            }
            $this->info("总计: {$result['total']} 条过期记录");
            return 0;
        }

        $cleaned = $service->cleanupExpiredData();
        $this->info("清理完成: {$cleaned} 条记录已处理");
        return 0;
    }
}
