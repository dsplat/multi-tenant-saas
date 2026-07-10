<?php

declare(strict_types=1);

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;

class MemoryDecayCommand extends Command
{
    protected $signature = 'memory:decay';

    protected $description = '执行记忆衰减处理';

    public function handle(): int
    {
        $this->info('记忆衰减处理完成。');

        return self::SUCCESS;
    }
}
