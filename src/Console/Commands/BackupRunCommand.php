<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\BackupService;

/**
 * 执行租户数据备份。
 */
class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--tenant= : 备份指定租户 ID (不指定则备份所有活跃租户)}';

    protected $description = '执行租户数据备份';

    public function handle(BackupService $backup): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $path = $backup->backupTenant((int) $tenantId);
            $this->info("租户 {$tenantId} 备份完成: {$path}");

            return self::SUCCESS;
        }

        $tenants = Tenant::where('status', 'active')->pluck('tenant_id');
        $this->info("开始备份 {$tenants->count()} 个活跃租户...");

        foreach ($tenants as $id) {
            try {
                $path = $backup->backupTenant($id);
                $this->line("  ✓ 租户 {$id}: {$path}");
            } catch (\Throwable $e) {
                $this->error("  ✗ 租户 {$id}: {$e->getMessage()}");
            }
        }

        $this->info('备份完成');

        return self::SUCCESS;
    }
}
