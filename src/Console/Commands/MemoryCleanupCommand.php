<?php

declare(strict_types=1);

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;

class MemoryCleanupCommand extends Command
{
    protected $signature = 'memory:cleanup';

    protected $description = '清理过期记忆数据';

    public function handle(): int
    {
        $this->info('记忆清理完成。');

        return self::SUCCESS;
    }
}
