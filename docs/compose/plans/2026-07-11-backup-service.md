# Backup Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a BackupService that provides tenant-level and system-level backup/restore with scheduling support.

**Architecture:** `BackupService` provides `backupTenant()`, `backupSystem()`, `restoreTenant()`, `restoreSystem()` methods. Tenant backup exports all rows with matching `tenant_id` from configured tables. System backup uses database dump. Backups stored as compressed JSON (tenant) or SQL (system) in configurable storage disk. CLI commands for manual operations. SchedulerService integration for automated backups.

**Tech Stack:** PHP JSON encoding, Laravel Storage facade, gzip compression, existing tenant_tables config.

## Global Constraints

- PHP ^8.3, Laravel ^13.0
- No new Composer dependencies (no spatie/laravel-backup)
- Tenant backup: JSON export of all tenant-scoped data
- System backup: SQL dump via shell (mysqldump) or PHP fallback
- Backups stored compressed (.json.gz / .sql.gz)
- Configurable retention policy
- All new code must pass Pint + existing test suite

---

### Task 1: Create BackupService — Tenant Backup

**Files:**
- Create: `src/Services/BackupService.php`
- Test: `tests/BackupServiceTest.php`

**Interfaces:**
- Produces: `BackupService::backupTenant(int $tenantId, ?string $disk = null): string` (returns backup path)
- Produces: `BackupService::restoreTenant(string $backupPath, int $tenantId, ?string $disk = null): array`
- Produces: `BackupService::listBackups(?string $disk = null): array`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\BackupService;

class BackupServiceTest extends TestCase
{
    protected BackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BackupService::class);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(BackupService::class, $this->service);
    }

    public function test_backup_tenant_returns_path(): void
    {
        config(['tenancy.backup.disk' => 'local']);
        $path = $this->service->backupTenant(1001);
        $this->assertNotEmpty($path);
        $this->assertStringEndsWith('.json.gz', $path);
    }

    public function test_list_backups_returns_array(): void
    {
        config(['tenancy.backup.disk' => 'local']);
        $backups = $this->service->listBackups();
        $this->assertIsArray($backups);
    }

    public function test_backup_tenant_includes_tenant_tables(): void
    {
        config(['tenancy.backup.disk' => 'local']);
        $path = $this->service->backupTenant(1001);
        $this->assertNotEmpty($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- BackupServiceTest`
Expected: FAIL

- [ ] **Step 3: Implement BackupService**

```php
<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 备份服务
 *
 * 提供租户级和系统级备份/恢复能力。
 * 租户备份: JSON 格式导出所有租户数据
 * 系统备份: SQL dump (MySQL) 或全库 JSON 导出
 */
class BackupService
{
    /**
     * 备份单个租户的所有数据。
     *
     * @return string 备份文件路径
     */
    public function backupTenant(int $tenantId, ?string $disk = null): string
    {
        $disk = $disk ?? config('tenancy.backup.disk', 'local');
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

        $filename = "backup_tenant_{$tenantId}_" . date('Ymd_His') . '.json.gz';
        $path = "backups/tenant_{$tenantId}/{$filename}";

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $compressed = gzencode($json, 6);

        File::ensureDirectoryExists(dirname($this->getFullPath($path)));
        File::put($this->getFullPath($path), $compressed);

        Log::info('[BackupService] Tenant backup created', [
            'tenant_id' => $tenantId,
            'path' => $path,
            'tables' => count($tables),
        ]);

        return $path;
    }

    /**
     * 从备份恢复租户数据。
     *
     * @return array{tables: int, rows: int}
     */
    public function restoreTenant(string $backupPath, int $tenantId, ?string $disk = null): array
    {
        $disk = $disk ?? config('tenancy.backup.disk', 'local');
        $fullPath = $this->getFullPath($backupPath);

        if (! File::exists($fullPath)) {
            throw new \RuntimeException("Backup file not found: {$backupPath}");
        }

        $compressed = File::get($fullPath);
        $json = gzdecode($compressed);
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['type'] ?? '') !== 'tenant') {
            throw new \RuntimeException('Invalid tenant backup file');
        }

        $tableCount = 0;
        $rowCount = 0;

        DB::transaction(function () use ($data, $tenantId, &$tableCount, &$rowCount) {
            foreach ($data['tables'] as $table => $rows) {
                // 清除目标租户的现有数据
                DB::table($table)->where('tenant_id', $tenantId)->delete();

                // 导入备份数据（更新 tenant_id 为目标租户）
                foreach ($rows as $row) {
                    $rowData = (array) $row;
                    $rowData['tenant_id'] = $tenantId;
                    DB::table($table)->insert($rowData);
                    $rowCount++;
                }
                $tableCount++;
            }
        });

        Log::info('[BackupService] Tenant backup restored', [
            'tenant_id' => $tenantId,
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
    public function listBackups(?string $disk = null): array
    {
        $disk = $disk ?? config('tenancy.backup.disk', 'local');
        $backupDir = $this->getFullPath('backups');

        if (! File::isDirectory($backupDir)) {
            return [];
        }

        $backups = [];
        $files = File::glob($backupDir . '/**/*.json.gz');

        foreach ($files as $file) {
            $relativePath = str_replace($this->getFullPath(''), '', $file);
            $backups[] = [
                'path' => $relativePath,
                'size' => File::size($file),
                'created_at' => date('Y-m-d H:i:s', File::lastModified($file)),
            ];
        }

        // 按创建时间降序
        usort($backups, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    /**
     * 删除指定备份。
     */
    public function deleteBackup(string $backupPath): bool
    {
        $fullPath = $this->getFullPath($backupPath);

        if (! File::exists($fullPath)) {
            return false;
        }

        File::delete($fullPath);

        // 如果目录为空，删除目录
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
        // 从配置获取，或自动发现包含 tenant_id 的表
        $configured = config('tenancy.backup.tables', []);

        if (! empty($configured)) {
            return $configured;
        }

        // 自动发现：查询所有包含 tenant_id 列的表
        $tables = [];
        $allTables = DB::select('SHOW TABLES');

        foreach ($allTables as $row) {
            $table = array_values((array) $row)[0];
            $columns = DB::select("SHOW COLUMNS FROM `{$table}`");

            foreach ($columns as $col) {
                if ($col->Field === 'tenant_id') {
                    $tables[] = $table;
                    break;
                }
            }
        }

        return $tables;
    }

    /**
     * 获取备份文件的完整路径。
     */
    protected function getFullPath(string $path): string
    {
        return storage_path('app/' . $path);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:filter -- BackupServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Services/BackupService.php tests/BackupServiceTest.php
git commit -m "feat: add BackupService with tenant backup/restore"
```

---

### Task 2: Create Backup CLI Commands

**Files:**
- Create: `src/Console/Commands/BackupRunCommand.php`
- Create: `src/Console/Commands/BackupListCommand.php`
- Create: `src/Console/Commands/BackupRestoreCommand.php`
- Modify: `src/TenancyServiceProvider.php` (register commands)

- [ ] **Step 1: Implement BackupRunCommand**

```php
<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\BackupService;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--tenant= : 备份指定租户 ID (不指定则备份所有活跃租户)}
        {--disk= : 存储磁盘 (默认 local)}';

    protected $description = '执行备份';

    public function handle(BackupService $backup): int
    {
        $tenantId = $this->option('tenant');
        $disk = $this->option('disk');

        if ($tenantId) {
            $path = $backup->backupTenant((int) $tenantId, $disk);
            $this->info("租户 {$tenantId} 备份完成: {$path}");

            return self::SUCCESS;
        }

        // 备份所有活跃租户
        $tenants = \MultiTenantSaas\Models\Tenant::where('status', 'active')->pluck('tenant_id');
        $this->info("开始备份 {$tenants->count()} 个活跃租户...");

        foreach ($tenants as $id) {
            try {
                $path = $backup->backupTenant($id, $disk);
                $this->line("  ✓ 租户 {$id}: {$path}");
            } catch (\Throwable $e) {
                $this->error("  ✗ 租户 {$id}: {$e->getMessage()}");
            }
        }

        $this->info('备份完成');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Implement BackupListCommand**

```php
<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\BackupService;

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
```

- [ ] **Step 3: Implement BackupRestoreCommand**

```php
<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\BackupService;

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
        $tenantId = $this->option('tenant');

        if (! $this->option('confirm')) {
            $this->warn('警告: 此操作将覆盖目标租户的所有数据!');
            if (! $this->confirm('确认继续?')) {
                $this->info('已取消');

                return self::SUCCESS;
            }
        }

        try {
            $result = $backup->restoreTenant($path, $tenantId ? (int) $tenantId : null);
            $this->info("恢复完成: {$result['tables']} 个表, {$result['rows']} 条记录");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("恢复失败: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
```

- [ ] **Step 4: Register commands in TenancyServiceProvider**

Add to commands array:

```php
\BackupRunCommand::class,
\BackupListCommand::class,
\BackupRestoreCommand::class,
```

- [ ] **Step 5: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/Backup*.php src/TenancyServiceProvider.php
git commit -m "feat: add backup:run, backup:list, backup:restore commands"
```

---

### Task 3: Add Backup Config and Scheduler

**Files:**
- Modify: `config/tenancy.php` (add backup config)
- Modify: `src/Services/SchedulerService.php` (add backup schedule)

- [ ] **Step 1: Add backup config**

Add to `config/tenancy.php`:

```php
    // 备份配置
    'backup' => [
        'disk' => env('TENANCY_BACKUP_DISK', 'local'),
        'keep_days' => 30,
        'schedule' => 'dailyAt:02:00',
        // 备份的表列表 (空 = 自动发现含 tenant_id 的表)
        'tables' => [],
    ],
```

- [ ] **Step 2: Add backup task to SchedulerService**

Add to `defineTasks()`:

```php
$this->addTask($schedule, 'backup', [
    'command' => 'backup:run',
    'schedule' => config('tenancy.backup.schedule', 'dailyAt:02:00'),
    'description' => '自动备份所有活跃租户数据',
]);
```

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add config/tenancy.php src/Services/SchedulerService.php
git commit -m "feat: add backup config and schedule"
```

---

### Task 4: Update Documentation

**Files:**
- Modify: `docs/zh/user-manual.md`

- [ ] **Step 1: Add backup section**

Add to `docs/zh/user-manual.md` after the Search section:

```markdown
### Backup & Restore

Tenant-level backup via `BackupService`. Exports all tenant data as compressed JSON.

```bash
# Backup single tenant
php artisan backup:run --tenant=1001

# Backup all active tenants
php artisan backup:run

# List backups
php artisan backup:list

# Restore from backup
php artisan backup:restore backups/tenant_1001/backup_tenant_1001_20260711_020000.json.gz --confirm

# Restore to different tenant
php artisan backup:restore path/to/backup.json.gz --tenant=2002 --confirm
```

**Config:** `config/tenancy.php` → `backup.disk`, `backup.keep_days`, `backup.tables`.

**Scheduled:** Auto-backup runs daily at 02:00 via SchedulerService.
```

- [ ] **Step 2: Run final tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add docs/zh/user-manual.md
git commit -m "docs: add backup section to user manual"
```
