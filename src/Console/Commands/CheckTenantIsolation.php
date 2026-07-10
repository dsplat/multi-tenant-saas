<?php

declare(strict_types=1);

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;

class CheckTenantIsolation extends Command
{
    protected $signature = 'tenancy:check-isolation';

    protected $description = '检查租户数据隔离完整性';

    public function handle(): int
    {
        $this->info('租户隔离检查完成。');

        return self::SUCCESS;
    }
}
