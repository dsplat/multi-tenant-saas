<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Infrastructure\Services\BackupService;

/**
 * 列出所有备份文件。
 */
class BackupListCommand extends Command
{
    protected $signature = 'backup:list
        {--json : 输出 JSON 格式}';

    protected $description = '列出所有备份文件';

    public function handle(BackupService $backup): int
    {
        $backups = $backup->listBackups();

        if ($this->option('json')) {
            $this->line(json_encode($backups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (empty($backups)) {
            $this->info('没有备份文件');

            return self::SUCCESS;
        }

        $rows = array_map(fn ($b) => [
            $b['path'],
            $this->formatSize($b['size']),
            $b['created_at'],
        ], $backups);

        $this->table(['Path', 'Size', 'Created'], $rows);

        return self::SUCCESS;
    }

    protected function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
