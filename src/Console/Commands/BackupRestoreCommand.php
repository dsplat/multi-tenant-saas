<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Infrastructure\Services\BackupService;

/**
 * 从备份恢复租户数据。
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
        {path : 备份文件路径}
        {--tenant= : 恢复到指定租户 ID (默认使用备份中的 tenant_id)}
        {--confirm : 跳过确认提示}';

    protected $description = '从备份恢复租户数据';

    public function handle(BackupService $backup): int
    {
        $path = $this->argument('path');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        if (! $this->option('confirm')) {
            $this->warn('警告: 此操作将覆盖目标租户的所有数据!');
            if (! $this->confirm('确认继续?')) {
                $this->info('已取消');

                return self::SUCCESS;
            }
        }

        try {
            $result = $backup->restoreTenant($path, $tenantId);
            $this->info("恢复完成: {$result['tables']} 个表, {$result['rows']} 条记录");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("恢复失败: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
