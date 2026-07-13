<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 备份服务
 *
 * 提供租户级备份/恢复能力。
 * 租户备份: JSON 格式导出所有含 tenant_id 的表数据，gzip 压缩存储。
 */
class BackupService
{
    /**
     * 备份单个租户的所有数据。
     *
     * @return string 备份文件相对路径
     */
    public function backupTenant(int $tenantId, ?string $disk = null): string
    {
        $tables = $this->getTenantTables();

        $data = [
            'version' => '1.0',
            'type' => 'tenant',
            'tenant_id' => $tenantId,
            'created_at' => now()->toISOString(),
            'tables' => [],
        ];

        foreach ($tables as $table) {
            $rows = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->get()
                ->toArray();

            $data['tables'][$table] = $rows;
        }

        $filename = 'backup_tenant_' . $tenantId . '_' . date('Ymd_His') . '.json.gz';
        $relativePath = "backups/tenant_{$tenantId}/{$filename}";
        $fullPath = storage_path('app/' . $relativePath);

        File::ensureDirectoryExists(dirname($fullPath));

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        File::put($fullPath, gzencode($json, 6));

        Log::info('[BackupService] Tenant backup created', [
            'tenant_id' => $tenantId,
            'path' => $relativePath,
            'tables' => count($tables),
        ]);

        return $relativePath;
    }

    /**
     * 从备份恢复租户数据。
     *
     * @return array{tables: int, rows: int}
     */
    public function restoreTenant(string $backupPath, ?int $tenantId = null): array
    {
        $fullPath = storage_path('app/' . $backupPath);

        if (! File::exists($fullPath)) {
            throw new \RuntimeException("Backup file not found: {$backupPath}");
        }

        $json = gzdecode(File::get($fullPath));
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['type'] ?? '') !== 'tenant') {
            throw new \RuntimeException('Invalid tenant backup file');
        }

        $targetTenantId = $tenantId ?? $data['tenant_id'];
        $tableCount = 0;
        $rowCount = 0;

        DB::transaction(function () use ($data, $targetTenantId, &$tableCount, &$rowCount) {
            foreach ($data['tables'] as $table => $rows) {
                DB::table($table)->where('tenant_id', $targetTenantId)->delete();

                foreach ($rows as $row) {
                    $rowData = (array) $row;
                    $rowData['tenant_id'] = $targetTenantId;
                    DB::table($table)->insert($rowData);
                    $rowCount++;
                }
                $tableCount++;
            }
        });

        Log::info('[BackupService] Tenant backup restored', [
            'tenant_id' => $targetTenantId,
            'backup' => $backupPath,
            'tables' => $tableCount,
            'rows' => $rowCount,
        ]);

        return ['tables' => $tableCount, 'rows' => $rowCount];
    }

    /**
     * 列出所有备份文件。
     *
     * @return array<int, array{path: string, size: int, created_at: string}>
     */
    public function listBackups(): array
    {
        $backupDir = storage_path('app/backups');

        if (! File::isDirectory($backupDir)) {
            return [];
        }

        $backups = [];
        $files = File::glob($backupDir . '/**/*.json.gz');

        foreach ($files as $file) {
            $relativePath = str_replace(storage_path('app/'), '', $file);
            $backups[] = [
                'path' => $relativePath,
                'size' => File::size($file),
                'created_at' => date('Y-m-d H:i:s', File::lastModified($file)),
            ];
        }

        usort($backups, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    /**
     * 删除指定备份。
     */
    public function deleteBackup(string $backupPath): bool
    {
        $fullPath = storage_path('app/' . $backupPath);

        if (! File::exists($fullPath)) {
            return false;
        }

        File::delete($fullPath);

        $dir = dirname($fullPath);
        if (File::isDirectory($dir) && empty(File::files($dir))) {
            File::deleteDirectory($dir);
        }

        return true;
    }

    /**
     * 清理过期备份。
     */
    public function cleanupOldBackups(int $keepDays = 30): int
    {
        $backups = $this->listBackups();
        $cutoff = now()->subDays($keepDays)->timestamp;
        $deleted = 0;

        foreach ($backups as $backup) {
            if (strtotime($backup['created_at']) < $cutoff) {
                if ($this->deleteBackup($backup['path'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * 获取需要备份的租户表列表。
     *
     * @return string[]
     */
    protected function getTenantTables(): array
    {
        $configured = config('tenancy.backup.tables', []);

        if (! empty($configured)) {
            return $configured;
        }

        $tables = [];
        $allTables = DB::select('SELECT name FROM sqlite_master WHERE type="table"');

        foreach ($allTables as $row) {
            $table = $row->name;
            if ($table === 'sqlite_sequence') {
                continue;
            }

            $columns = DB::select("PRAGMA table_info(`{$table}`)");
            foreach ($columns as $col) {
                if ($col->name === 'tenant_id') {
                    $tables[] = $table;
                    break;
                }
            }
        }

        return $tables;
    }
}
